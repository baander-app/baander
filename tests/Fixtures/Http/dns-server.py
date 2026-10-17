#!/usr/bin/env python3
"""Loopback-only DNS answers for the disposable native resolver drill."""
import argparse
import ipaddress
import socketserver
import struct
import threading
import time
from pathlib import Path


def answer(packet, tcp=False):
    ident, flags, questions, _, _, _ = struct.unpack('!6H', packet[:12])
    if questions != 1:
        return None
    offset, labels = 12, []
    while packet[offset]:
        length = packet[offset]
        offset += 1
        labels.append(packet[offset:offset + length].decode('ascii'))
        offset += length
    offset += 1
    kind, family = struct.unpack('!2H', packet[offset:offset + 4])
    question = packet[12:offset + 4]
    host = '.'.join(labels).lower()
    if not host.endswith('.baander.app') or family != 1:
        code, addresses = 3, []
    elif host == 'stall.baander.app' or (host == 'orphan.baander.app' and kind == 28):
        return None
    elif host == 'family-error.baander.app' and kind == 28:
        code, addresses = 2, []
    else:
        code, addresses = 0, []
        if kind == 1:
            if host == 'orphan.baander.app':
                time.sleep(0.3)
            addresses = ['8.8.8.8']
            if host == 'many.baander.app':
                addresses = [f'8.8.4.{i}' for i in range(1, 101)] + ['10.0.0.1']
        elif kind == 28 and host != 'nodata.baander.app' and host != 'many.baander.app':
            addresses = ['fd00::1' if host == 'private6.baander.app' else '2001:4860:4860::8888']
    records = b''
    for address in addresses:
        raw = ipaddress.ip_address(address).packed
        records += b'\xc0\x0c' + struct.pack('!HHIH', kind, 1, 0, len(raw)) + raw
    reply = struct.pack('!6H', ident, 0x8080 | (flags & 0x0100) | code, 1, len(addresses), 0, 0) + question + records
    if len(reply) > 512 and not tcp:
        return struct.pack('!6H', ident, 0x8280 | (flags & 0x0100), 1, 0, 0, 0) + question
    return reply


class UDP(socketserver.BaseRequestHandler):
    def handle(self):
        packet, connection = self.request
        reply = answer(packet)
        if reply is not None:
            connection.sendto(reply, self.client_address)


class TCP(socketserver.BaseRequestHandler):
    def handle(self):
        self.request.settimeout(2)
        length = self.request.recv(2)
        if len(length) != 2:
            return
        remaining = struct.unpack('!H', length)[0]
        packet = b''
        while remaining:
            chunk = self.request.recv(remaining)
            if not chunk:
                return
            packet += chunk
            remaining -= len(chunk)
        reply = answer(packet, tcp=True)
        if reply is not None:
            self.request.sendall(struct.pack('!H', len(reply)) + reply)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--ready-file', required=True)
    args = parser.parse_args()
    udp = socketserver.ThreadingUDPServer(('127.0.0.1', 53), UDP)
    tcp = socketserver.ThreadingTCPServer(('127.0.0.1', 53), TCP)
    udp.daemon_threads = tcp.daemon_threads = True
    threading.Thread(target=tcp.serve_forever, daemon=True).start()
    Path(args.ready_file).write_text('ready\n')
    udp.serve_forever()
