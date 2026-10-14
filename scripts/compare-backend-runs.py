#!/usr/bin/env python3
"""Compare exact completed identities and executable coverage between two runs."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parent.parent
EXTRACT_COVERAGE = r"""
require $argv[1];
$data = (new SebastianBergmann\CodeCoverage\Serialization\Merger())->merge(array_slice($argv, 2));
$files = $lines = $covered = [];
foreach ($data['codeCoverage']->lineCoverage() as $file => $entries) {
    $absolute = str_starts_with($file, '/') ? $file : rtrim($data['basePath'], '/') . '/' . $file;
    $files[] = $absolute;
    foreach ($entries as $number => $tests) {
        if ($tests === null) {
            continue;
        }
        $lines[] = [$absolute, $number];
        if ($tests !== []) {
            $covered[] = [$absolute, $number];
        }
    }
}
echo json_encode(['files' => $files, 'lines' => $lines, 'covered' => $covered], JSON_THROW_ON_ERROR);
"""


def identities(directory):
    discovery = json.loads((directory / 'run/discovery.json').read_text())
    expected = sorted(identity for values in discovery['files'].values() for identity in values)
    observed = []
    for file in (directory / 'run').glob('*.result.json'):
        result = json.loads(file.read_text())
        if result['status'] != 0 or result['runId'] != discovery['runId']:
            raise ValueError(f'Failed or stale result: {file}')
        observed.extend(result['identities'])
    if not expected or len(expected) != len(set(expected)) or expected != sorted(observed):
        raise ValueError(f'Incomplete or duplicate results: {directory}')
    return expected, discovery.get('seed'), discovery['coverage']


def coverage(directory):
    discovery = json.loads((directory / 'run/discovery.json').read_text())
    paths = []
    for result_path in sorted((directory / 'run').glob('*.result.json')):
        result = json.loads(result_path.read_text())
        path = result_path.with_name(result_path.name.removesuffix('.result.json') + '.cov')
        if result['status'] != 0 or result['runId'] != discovery['runId']:
            raise ValueError(f'Failed or stale coverage result: {result_path}')
        if not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != result.get('hashes', {}).get('coverage'):
            raise ValueError(f'Missing or changed serialized coverage: {path}')
        paths.append(str(path.resolve()))
    if not paths:
        raise ValueError('No serialized coverage lanes found')
    extracted = subprocess.run(['php', '-d', 'memory_limit=2G', '-r', EXTRACT_COVERAGE,
                                str(ROOT / 'vendor/autoload.php'), *paths],
                               capture_output=True, text=True, timeout=120)
    if extracted.returncode:
        raise ValueError('Cannot read serialized coverage: ' + extracted.stderr.strip())
    data = json.loads(extracted.stdout)
    lines = {tuple(identity) for identity in data['lines']}
    covered = {tuple(identity) for identity in data['covered']}
    project = ET.parse(directory / 'reports/clover.xml').getroot().find('project')
    metrics = project.find('metrics')
    denominators = {name: int(metrics.get(name)) for name in ('files', 'classes', 'methods', 'statements')}
    if denominators['statements'] <= 0 or len(lines) != denominators['statements']:
        raise ValueError('Clover line denominator disagrees with serialized executable lines')
    if len(data['files']) != denominators['files'] or len(covered) != int(metrics.get('coveredstatements')):
        raise ValueError('Clover file or covered-line count disagrees with serialized coverage')
    return denominators, lines, covered


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('reference', type=Path)
    parser.add_argument('candidate', type=Path)
    args = parser.parse_args()
    expected, seed, has_coverage = identities(args.reference)
    actual, candidate_seed, candidate_coverage = identities(args.candidate)
    if expected != actual or seed != candidate_seed or has_coverage != candidate_coverage:
        raise ValueError('Selected/completed identities, seed or coverage mode differ')
    result = {'tests': len(expected), 'seed': seed, 'coverage': has_coverage}
    if has_coverage:
        baseline, executable, covered = coverage(args.reference)
        candidate, candidate_executable, candidate_covered = coverage(args.candidate)
        if baseline != candidate or executable != candidate_executable or covered != candidate_covered:
            raise ValueError(json.dumps({'denominators': [baseline, candidate],
                                        'executable_difference': len(executable ^ candidate_executable),
                                        'covered_difference': len(covered ^ candidate_covered)}))
        result.update(denominators=baseline, covered_lines=len(covered))
    for label, directory in (('reference', args.reference), ('candidate', args.candidate)):
        timing = json.loads((directory / 'timing.json').read_text())
        if timing['status'] != 0:
            raise ValueError(f'{label} run did not complete successfully')
        result[label + '_seconds'] = timing['elapsed']
    result['speedup'] = result['reference_seconds'] / result['candidate_seconds']
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
