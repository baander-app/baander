#!/usr/bin/env python3
"""Validate backend coverage reports before publishing their summary metrics."""

import argparse
from decimal import Decimal, InvalidOperation
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import xml.etree.ElementTree as ET


LABELS = {"Lines": "lines", "Methods": "methods", "Classes": "classes"}
SUMMARY = re.compile(r"\s*(Lines|Methods|Classes):\s*(\S+)%\s*(?:\((\d+)/(\d+)\))?\s*")


def text_metrics(path):
    result = {}
    for line in path.read_text().splitlines():
        candidate = re.match(r"\s*(Lines|Methods|Classes):", line)
        if not candidate:
            continue
        # Detailed PHPUnit class rows contain multiple metrics on one line.
        if candidate[1] == "Methods" and "Lines:" in line:
            continue
        match = SUMMARY.fullmatch(line)
        if not match:
            raise ValueError(f"Malformed coverage summary: {line.strip()}")
        name = LABELS[match[1]]
        if name in result:
            raise ValueError(f"Duplicate {match[1]} coverage summary")
        try:
            percentage = Decimal(match[2])
        except InvalidOperation as error:
            raise ValueError(f"Invalid {match[1]} percentage") from error
        if not percentage.is_finite() or not 0 <= percentage <= 100:
            raise ValueError(f"Invalid {match[1]} percentage")
        counts = tuple(map(int, match.group(3, 4))) if match[3] is not None else None
        result[name] = (percentage, counts)
    if set(result) != set(LABELS.values()):
        raise ValueError("Coverage summary must contain Lines, Methods and Classes exactly once")
    return result


def count(metrics, name):
    value = metrics.get(name)
    if value is None or not re.fullmatch(r"[0-9]+", value):
        raise ValueError(f"Clover {name} must be a nonnegative integer")
    return int(value)


def pair(metrics, total_name):
    total = count(metrics, total_name)
    covered = count(metrics, "covered" + total_name)
    if covered > total:
        raise ValueError(f"Clover covered{total_name} exceeds {total_name}")
    return covered, total


def one_metrics(node):
    metrics = node.findall("metrics")
    if len(metrics) != 1:
        raise ValueError("Each Clover project, file and class must have exactly one metrics element")
    result = metrics[0]
    for name in ("files", "classes", "loc", "ncloc"):
        if name in result.attrib:
            count(result, name)
    for name in ("statements", "methods", "conditionals", "elements", "classes"):
        if "covered" + name in result.attrib:
            pair(result, name)
    return result


def clover_metrics(path):
    document = ET.parse(path).getroot()
    if document.tag != "coverage" or len(document.findall("project")) != 1:
        raise ValueError("Clover must contain one coverage project")
    project = document.find("project")
    metrics = one_metrics(project)
    result = {"lines": pair(metrics, "statements"), "methods": pair(metrics, "methods")}
    files = project.findall(".//file")
    if not files or count(metrics, "files") != len(files):
        raise ValueError("Clover file count is empty or inconsistent")
    totals = {"statements": [0, 0], "methods": [0, 0]}
    classes = covered_classes = 0
    for file in files:
        file_metrics = one_metrics(file)
        for name in totals:
            values = pair(file_metrics, name)
            totals[name] = [left + right for left, right in zip(totals[name], values)]
        file_classes = 0
        for class_node in file.findall("class"):
            class_metrics = one_metrics(class_node)
            statements = pair(class_metrics, "statements")
            methods = pair(class_metrics, "methods")
            conditionals = pair(class_metrics, "conditionals")
            if methods[1] == 0:
                continue
            file_classes += 1
            classes += 1
            # php-code-coverage counts executable classes fully covered by branches,
            # or by lines when no branch has been covered.
            coverage = conditionals if conditionals[0] > 0 else statements
            covered_classes += coverage[1] > 0 and coverage[0] == coverage[1]
        if file_classes != count(file_metrics, "classes"):
            raise ValueError("Clover file class denominator is inconsistent")
    for name, aggregate in (("statements", "lines"), ("methods", "methods")):
        if tuple(totals[name]) != result[aggregate]:
            raise ValueError(f"Clover project {name} counts disagree with its files")
    if classes != count(metrics, "classes"):
        raise ValueError("Clover project class denominator is inconsistent")
    result["classes"] = (covered_classes, classes)
    if "coveredclasses" in metrics.attrib and count(metrics, "coveredclasses") != covered_classes:
        raise ValueError("Clover project covered class count is inconsistent")
    if any(total <= 0 for _, total in result.values()):
        raise ValueError("Coverage denominators must be positive")
    return result


def validate(directory):
    summary = text_metrics(directory / "report.txt")
    clover = clover_metrics(directory / "clover.xml")
    output = {}
    for name, (percentage, text_counts) in summary.items():
        covered, total = clover[name]
        if text_counts is not None and text_counts != (covered, total):
            raise ValueError(f"{name} text counts disagree with Clover")
        expected = Decimal(covered) * 100 / Decimal(total)
        if abs(percentage - expected) > Decimal("0.01"):
            raise ValueError(f"{name} text percentage disagrees with Clover")
        output[name] = float(percentage)
    # Only validated metrics replace the published summary; interrupted writes
    # leave the previous file intact.
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode="w", dir=directory, prefix=".metrics-",
                                         suffix=".json", delete=False) as stream:
            temporary = Path(stream.name)
            json.dump(output, stream, allow_nan=False)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, directory / "metrics.json")
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
    return output


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path, nargs="?", default=Path("coverage"))
    args = parser.parse_args()
    try:
        metrics = validate(args.directory)
    except (OSError, ValueError, ET.ParseError) as error:
        print(f"Coverage validation failed: {error}", file=sys.stderr)
        return 1
    print("Coverage validated: " + ", ".join(f"{name} {value:.2f}%" for name, value in metrics.items()))
    return 0


if __name__ == "__main__":
    sys.exit(main())
