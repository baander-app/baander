#!/usr/bin/env python3
"""Compare exact completed identities and executable coverage between two runs."""
import argparse
import json
from pathlib import Path
import xml.etree.ElementTree as ET


def identities(directory):
    discovery = json.loads((directory / 'run/discovery.json').read_text())
    expected = sorted(identity for values in discovery['files'].values() for identity in values)
    observed = []
    for file in (directory / 'run').glob('*.result.json'):
        result = json.loads(file.read_text())
        if result['status'] != 0 or result['runId'] != discovery['runId']:
            raise ValueError(f'Failed or stale result: {file}')
        observed.extend(result['identities'])
    if len(expected) != len(set(expected)) or expected != sorted(observed):
        raise ValueError(f'Incomplete or duplicate results: {directory}')
    return expected, discovery.get('seed'), discovery['coverage']


def coverage(directory):
    project = ET.parse(directory / 'reports/clover.xml').getroot().find('project')
    lines, covered = set(), set()
    for file in project.findall('.//file'):
        for line in file.findall('line'):
            if line.get('type') != 'stmt':
                continue
            identity = (file.get('name'), int(line.get('num')))
            if identity in lines:
                raise ValueError('Duplicate executable coverage line')
            lines.add(identity)
            if int(line.get('count')) > 0:
                covered.add(identity)
    metrics = project.find('metrics')
    denominators = {name: int(metrics.get(name)) for name in ('files', 'classes', 'methods', 'statements')}
    if len(lines) != denominators['statements']:
        raise ValueError('Clover line denominator disagrees with executable lines')
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
