#!/usr/bin/env python3
"""Exercise queued cataloging and complete audio responses on an owned dev instance."""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import ssl
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives.asymmetric.utils import decode_dss_signature

from dev_environment.model import StateStore, atomic_json


def b64(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).rstrip(b'=').decode()


class Client:
    def __init__(self, origin: str, ca: Path):
        self.origin = origin.rstrip('/')
        self.key = ec.generate_private_key(ec.SECP256R1())
        numbers = self.key.public_key().public_numbers()
        self.jwk = {'kty': 'EC', 'crv': 'P-256',
                    'x': b64(numbers.x.to_bytes(32, 'big')), 'y': b64(numbers.y.to_bytes(32, 'big'))}
        self.token = None
        self.nonce = None
        self.http = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=str(ca))))

    def proof(self, method: str, url: str) -> str:
        parts = urllib.parse.urlsplit(url)
        claims = {'htm': method, 'htu': urllib.parse.urlunsplit(parts._replace(query='', fragment='')),
                  'iat': int(time.time()), 'jti': str(uuid.uuid4())}
        if self.nonce:
            claims['nonce'] = self.nonce
        if self.token:
            claims['ath'] = b64(hashlib.sha256(self.token.encode()).digest())
        header = {'typ': 'dpop+jwt', 'alg': 'ES256', 'jwk': self.jwk}
        message = '.'.join(b64(json.dumps(value, separators=(',', ':')).encode()) for value in (header, claims))
        r, s = decode_dss_signature(self.key.sign(message.encode(), ec.ECDSA(hashes.SHA256())))
        return message + '.' + b64(r.to_bytes(32, 'big') + s.to_bytes(32, 'big'))

    def request(self, path: str, method: str = 'GET', payload=None, destination: Path | None = None):
        url = self.origin + path
        body = json.dumps(payload).encode() if payload is not None else None
        for attempt in range(3):
            headers = {'DPoP': self.proof(method, url), 'Accept': 'application/json'}
            if body is not None:
                headers['Content-Type'] = 'application/json'
            if self.token:
                headers['Authorization'] = 'DPoP ' + self.token
            request = urllib.request.Request(url, data=body, headers=headers, method=method)
            try:
                with self.http.open(request, timeout=180) as response:
                    self.nonce = response.headers.get('DPoP-Nonce', self.nonce)
                    if destination is None:
                        return response.status, json.load(response)
                    digest = hashlib.sha256()
                    length = 0
                    with destination.open('wb') as output:
                        while chunk := response.read(65536):
                            output.write(chunk)
                            digest.update(chunk)
                            length += len(chunk)
                    declared = response.headers.get('Content-Length')
                    if declared is not None and length != int(declared):
                        raise RuntimeError('Audio response ended before Content-Length.')
                    return {'status': response.status, 'bytes': length, 'sha256': digest.hexdigest(),
                            'contentType': response.headers.get('Content-Type'), 'contentLength': declared,
                            'readToEof': True}
            except urllib.error.HTTPError as error:
                nonce = error.headers.get('DPoP-Nonce')
                detail = error.read(4096).decode(errors='replace')
                if nonce and attempt < 2 and ('use_dpop_nonce' in detail or 'invalid_dpop_proof' in detail):
                    self.nonce = nonce
                    continue
                raise RuntimeError(f'{method} {path} returned HTTP {error.code}: {detail}') from error
        raise RuntimeError('DPoP nonce negotiation did not complete.')


def run(arguments: list[str], timeout: int = 60) -> bytes:
    result = subprocess.run(arguments, capture_output=True, timeout=timeout)
    if result.returncode:
        raise RuntimeError(f'{arguments[0]} failed: {result.stderr.decode(errors="replace")[-2000:]}')
    return result.stdout


def decoded_audio(path: Path) -> dict:
    metadata = json.loads(run(['ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', str(path)]))
    audio = [stream for stream in metadata['streams'] if stream['codec_type'] == 'audio']
    if len(audio) != 1:
        raise RuntimeError('Playback response must contain exactly one audio stream.')
    pcm = run(['ffmpeg', '-v', 'error', '-xerror', '-i', str(path), '-map', '0:a:0',
               '-ac', '1', '-ar', '8000', '-f', 's16le', '-'])
    duration = len(pcm) / 16000
    if abs(duration - 8) > 0.05 or not any(pcm):
        raise RuntimeError(f'Audio did not decode the complete eight second tone: {duration}s.')
    return {'codec': audio[0]['codec_name'], 'decodedSeconds': duration,
            'decodedSamples': len(pcm) // 2, 'nonSilent': True, 'decoderReachedEof': True}


def qualify(args) -> dict:
    workspace = args.workspace.absolute()
    if not (workspace / 'disposable-qualification').is_file():
        raise RuntimeError('Media qualification requires a marked disposable workspace.')
    media = workspace / 'media'
    media.mkdir(parents=True, exist_ok=True)
    fixture = media / 'workflow-qualification-tone.flac'
    # Only the harness-owned generated fixture is written; application mounts are read-only.
    if not fixture.exists():
        run(['ffmpeg', '-v', 'error', '-nostdin', '-n', '-f', 'lavfi', '-i',
             'sine=frequency=440:sample_rate=48000:duration=8', '-metadata',
             'title=Workflow qualification tone', '-metadata', 'artist=Baander qualification',
             '-metadata', 'album=Development workflow', '-c:a', 'flac', str(fixture)])
    source_hash = hashlib.sha256(fixture.read_bytes()).hexdigest()
    store = StateStore(args.state_root)
    instance = store.load(args.instance)
    if not instance:
        raise RuntimeError('The acceptance instance does not exist.')
    if instance.get('repository') != str(workspace / 'origin.git'):
        raise RuntimeError('The instance must belong to this disposable qualification remote.')
    mount = next((item for item in instance.get('media', [])
                  if Path(item['source']).resolve() == media.resolve()), None)
    if mount is None:
        raise RuntimeError('The owned fixture directory must be mounted in the acceptance instance.')
    compose = json.loads(Path(instance['compose_file']).read_text())
    configured_mount = next((item for item in compose['services']['app']['volumes']
                             if isinstance(item, dict) and item.get('target') == mount['target']), None)
    if configured_mount is None or configured_mount.get('read_only') is not True:
        raise RuntimeError('The application fixture mount must be read-only.')
    client = Client('https://' + instance['hostname'], args.ca_file or args.state_root / 'host-setup/ca/rootCA.pem')
    status, login = client.request('/api/auth/login', 'POST',
                                   {'email': 'admin@baander.test', 'password': 'admin', 'totpCode': ''})
    client.token = login['data']['accessToken']
    status, settings = client.request('/api/admin/settings', 'PATCH',
                                      {'settings': {'transcode.enabled': True}})
    transcode_setting = next((item for item in settings['data'] if item['key'] == 'transcode.enabled'), None)
    if status != 200 or transcode_setting is None or transcode_setting['value'] is not True:
        raise RuntimeError('The disposable qualification instance did not enable audio transcoding.')
    setting_status = status
    status, libraries = client.request('/api/libraries')
    library = next((item for item in libraries['data'] if item['path'] == mount['target'] and item['type'] == 'music'), None)
    if library is None:
        status, created = client.request('/api/libraries', 'POST',
                                        {'name': 'Workflow qualification music', 'path': mount['target'],
                                         'type': 'music', 'filesystemType': 'local'})
        if status != 201:
            raise RuntimeError('The music library was not created.')
        library = created['data']
    identifier = library['publicId']
    if args.existing_scan:
        queued_status = None
        queued = {'data': library}
        if library['scanStatus'] != 'completed':
            raise RuntimeError('Catalog resume requires an already completed scan.')
    else:
        status, queued = client.request(f'/api/libraries/{identifier}/scan', 'POST', {})
        if status != 202:
            raise RuntimeError('The HTTP scan did not return the queued response.')
        queued_status = status
    deadline = time.monotonic() + args.timeout
    while time.monotonic() < deadline:
        _, current = client.request('/api/libraries/' + identifier)
        state = current['data']['scanStatus']
        if state == 'failed':
            raise RuntimeError('The queued library scan failed.')
        if state == 'completed':
            break
        time.sleep(1)
    else:
        raise RuntimeError('The queued scan did not complete before the deadline.')
    # Scan completion records durable discovery. Catalog ingestion consumes those
    # records asynchronously, so qualify its visible result separately.
    while time.monotonic() < deadline:
        _, stats = client.request(f'/api/libraries/{identifier}/stats')
        _, songs = client.request('/api/songs/?limit=100&q=' + urllib.parse.quote('Workflow qualification tone'))
        song = next((item for item in songs['data']
                     if str(item.get('path', '')).endswith(fixture.name) and item.get('available') is True), None)
        if song and stats['data']['songs'] >= 1:
            break
        time.sleep(1)
    else:
        raise RuntimeError('The completed scan did not catalog the fixture before the deadline.')
    evidence = workspace / 'playback-evidence'
    evidence.mkdir(exist_ok=True)
    responses = {}
    for name, suffix in [('original', ''), ('transcoded', '&format=opus&bitrate=96000')]:
        destination = evidence / (name + ('.flac' if name == 'original' else '.opus'))
        result = client.request('/api/stream/track?id=' + song['publicId'] + suffix, destination=destination)
        result.update(decoded_audio(destination))
        if result['status'] != 200 or result['bytes'] == 0 or not result['contentType'].startswith('audio/'):
            raise RuntimeError('The audio endpoint did not serve a complete audio response.')
        responses[name] = result
    if responses['original']['sha256'] != source_hash:
        raise RuntimeError('Original playback bytes differ from the read-only source.')
    if responses['transcoded']['codec'] != 'opus':
        raise RuntimeError('The requested Opus conversion was not served.')
    if hashlib.sha256(fixture.read_bytes()).hexdigest() != source_hash:
        raise RuntimeError('The source fixture changed during qualification.')
    return {'passed': True, 'origin': client.origin, 'tlsVerified': True,
            'authentication': 'seeded-development-account-dpop', 'libraryId': identifier,
            'transcodeSettingStatus': setting_status,
            'transcodeEnabled': transcode_setting['value'],
            'scanQueuedStatus': queued_status,
            'resumedExistingScan': args.existing_scan,
            'scanQueuedState': queued['data']['scanStatus'], 'sourceMountReadOnly': True,
            'scanStatus': current['data']['scanStatus'], 'catalogedSongs': stats['data']['songs'],
            'songId': song['publicId'], 'sourceUnchanged': True, 'responses': responses}


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--state-root', type=Path, required=True)
    parser.add_argument('--workspace', type=Path, required=True)
    parser.add_argument('--instance', default='persistent')
    parser.add_argument('--ca-file', type=Path)
    parser.add_argument('--timeout', type=int, default=180)
    parser.add_argument('--existing-scan', action='store_true',
                        help='Resume catalog and playback verification without submitting another scan.')
    args = parser.parse_args()
    os.umask(0o077)
    output = args.workspace / 'media-evidence.json'
    try:
        result = qualify(args)
    except Exception as error:
        atomic_json(output, {'passed': False, 'error': str(error)})
        raise
    atomic_json(output, result)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
