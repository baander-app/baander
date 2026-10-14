"""Coordinator isolation and lifecycle checks without Docker services."""

import importlib.util
import io
import json
from pathlib import Path
import signal
import subprocess
import tempfile
import unittest
from unittest.mock import MagicMock, patch


SCRIPT = Path(__file__).resolve().parents[1] / "test-backend-container.py"
SPEC = importlib.util.spec_from_file_location("backend_coordinator", SCRIPT)
coordinator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(coordinator)


class PartitionTest(unittest.TestCase):
    def test_each_selected_file_occurs_once_across_lanes(self):
        files = [
            "tests/Unit/OrdinaryTest.php",
            "tests/Unit/NativeTest.php",
            "packages/baander-phpstan-rules/tests/RuleTest.php",
            *[f"tests/Integration/Case{number}Test.php" for number in range(5)],
            "tests/Functional/ControllerTest.php",
        ]
        lanes = coordinator.partition(files, {files[1]: "native reactor"}, 2)
        actual = [file for lane in lanes.values() for file in lane["files"]]
        self.assertCountEqual(files, actual)
        self.assertEqual(len(actual), len(set(actual)))
        native = [lane for lane in lanes.values() if lane["privileged"]]
        self.assertEqual([files[1]], native[0]["files"])
        self.assertEqual(1, native[0]["processes"])
        self.assertEqual(1, native[0]["batch"])
        self.assertFalse(native[0]["services"])

    def test_service_files_are_round_robin_and_use_fresh_processes(self):
        files = [f"tests/Integration/Case{number}Test.php" for number in range(7)]
        lanes = coordinator.partition(files, {}, 3)
        service = [lane for lane in lanes.values() if lane["services"]]
        self.assertEqual(3, len(service))
        self.assertEqual([files[index::3] for index in range(3)], [lane["files"] for lane in service])
        for lane in service:
            self.assertEqual(1, lane["processes"])
            self.assertEqual(1, lane["batch"])
            self.assertTrue(lane["privileged"])

    def test_fewer_service_files_create_fewer_stacks(self):
        lanes = coordinator.partition(["tests/Functional/OnlyTest.php"], {}, 8)
        self.assertEqual(1, sum(bool(lane["services"]) for lane in lanes.values()))

    def test_ordinary_workers_recycle_after_twenty_five_files(self):
        lanes = coordinator.partition(["tests/Unit/FirstTest.php", "tests/Unit/SecondTest.php"], {}, 2)
        ordinary = next(lane for lane in lanes.values() if not lane["services"] and not lane["privileged"])
        self.assertEqual(2, ordinary["processes"])
        self.assertEqual(25, ordinary["batch"])

    def test_absolute_discovery_paths_match_relative_native_manifest(self):
        native = "tests/Unit/NativeTest.php"
        selected = f"{coordinator.CONTAINER_ROOT}/{native}"
        lanes = coordinator.partition([selected], {native: "native reactor"}, 2)
        self.assertEqual([selected], lanes["native"]["files"])

    def test_duplicate_and_empty_discovery_are_rejected(self):
        for files in ([], ["tests/Unit/Test.php", "tests/Unit/Test.php"]):
            with self.subTest(files=files), self.assertRaises(ValueError):
                coordinator.partition(files, {}, 2)


class ConfigurationTest(unittest.TestCase):
    def test_process_count_accepts_boundary_values(self):
        for value in ("1", "8"):
            self.assertEqual(int(value), coordinator.positive_integer(value, "BAANDER_TEST_PROCESSES", maximum=8))

    def test_process_count_rejects_invalid_values(self):
        for value in ("0", "-1", "9", "1.5", "two", ""):
            with self.subTest(value=value), self.assertRaises((ValueError, RuntimeError)):
                coordinator.positive_integer(value, "BAANDER_TEST_PROCESSES", maximum=8)

    def test_native_manifest_names_existing_tests_with_reasons(self):
        root = SCRIPT.parents[1]
        manifest = json.loads((SCRIPT.parent / "backend-native-tests.json").read_text())
        self.assertTrue(manifest)
        for path, reason in manifest.items():
            with self.subTest(path=path):
                self.assertTrue((root / path).is_file())
                self.assertTrue(path.endswith("Test.php"))
                self.assertTrue(reason.strip())


class LifecycleTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.runtime = coordinator.Runtime(Path(self.directory.name), 60)

    def test_expired_deadline_prevents_new_commands(self):
        self.runtime.deadline = -1
        with patch.object(coordinator.subprocess, "Popen") as spawn:
            with self.assertRaises(TimeoutError):
                self.runtime.command(["docker", "version"])
            spawn.assert_not_called()

    def test_interruption_prevents_new_commands(self):
        self.runtime.stopped.set()
        with patch.object(coordinator.subprocess, "Popen") as spawn:
            with self.assertRaises(RuntimeError):
                self.runtime.command(["docker", "version"])
            spawn.assert_not_called()

    def test_running_command_is_killed_as_a_process_group_on_timeout(self):
        self.runtime.deadline = 1
        process = MagicMock()
        process.pid = 12345
        process.poll.return_value = None
        with patch.object(coordinator.subprocess, "Popen") as spawn, \
                patch.object(coordinator.time, "monotonic", side_effect=[0, 2]), \
                patch.object(coordinator.os, "killpg") as kill:
            spawn.return_value.__enter__.return_value = process
            with self.assertRaises(TimeoutError):
                self.runtime.command(["docker", "exec", "app", "php"])
            kill.assert_called_once_with(12345, signal.SIGKILL)
            process.wait.assert_called_once()
            self.assertTrue(self.runtime.stopped.is_set())
            self.assertTrue(spawn.call_args.kwargs["start_new_session"])

    def test_cleanup_runs_after_timeout_and_removes_containers_before_networks(self):
        self.runtime.deadline = -1
        self.runtime.stopped.set()
        self.runtime.containers = ["app", "postgres"]
        self.runtime.networks = ["stack"]
        with patch.object(coordinator.subprocess, "run", return_value=subprocess.CompletedProcess([], 0)) as run:
            self.assertTrue(self.runtime.cleanup())
        commands = [call.args[0] for call in run.call_args_list]
        self.assertEqual([
            ["docker", "logs", "postgres"],
            ["docker", "rm", "-f", "postgres"],
            ["docker", "logs", "app"],
            ["docker", "rm", "-f", "app"],
            ["docker", "network", "rm", "stack"],
        ], commands)
        self.assertTrue(all(call.kwargs["timeout"] <= 20 for call in run.call_args_list))

    def test_failed_log_collection_does_not_skip_container_removal(self):
        self.runtime.containers = ["app"]

        def execute(args, **kwargs):
            if args[1] == "logs":
                raise subprocess.TimeoutExpired(args, kwargs["timeout"])
            return subprocess.CompletedProcess(args, 0)

        with patch.object(coordinator.subprocess, "run", side_effect=execute) as run, \
                patch.object(coordinator.sys, "stderr", io.StringIO()):
            self.assertTrue(self.runtime.cleanup())
        self.assertIn(["docker", "rm", "-f", "app"], [call.args[0] for call in run.call_args_list])

    def test_failed_removal_exit_codes_fail_cleanup(self):
        self.runtime.containers = ["app"]
        self.runtime.networks = ["stack"]
        for failed in (["docker", "rm", "-f", "app"], ["docker", "network", "rm", "stack"]):
            def execute(args, **kwargs):
                return subprocess.CompletedProcess(args, 1 if args == failed else 0)

            with self.subTest(failed=failed), \
                    patch.object(coordinator.subprocess, "run", side_effect=execute) as run, \
                    patch.object(coordinator.sys, "stderr", io.StringIO()):
                self.assertFalse(self.runtime.cleanup())
                self.assertIn(["docker", "network", "rm", "stack"], [call.args[0] for call in run.call_args_list])

    def test_removal_timeouts_and_os_errors_fail_cleanup(self):
        self.runtime.containers = ["app"]
        self.runtime.networks = ["stack"]
        for error in (subprocess.TimeoutExpired(["docker", "rm"], 20), OSError("Docker unavailable")):
            def execute(args, **kwargs):
                if args[1] == "rm":
                    raise error
                return subprocess.CompletedProcess(args, 0)

            with self.subTest(error=type(error).__name__), \
                    patch.object(coordinator.subprocess, "run", side_effect=execute) as run, \
                    patch.object(coordinator.sys, "stderr", io.StringIO()):
                self.assertFalse(self.runtime.cleanup())
                self.assertIn(["docker", "network", "rm", "stack"], [call.args[0] for call in run.call_args_list])

    def test_service_app_exports_nested_process_dsns_without_shared_mounts(self):
        with patch.object(self.runtime, "docker") as docker:
            self.runtime.create_app("stack-1", "private-network", True, True)
        args = docker.call_args_list[0].args
        environment = dict(args[index + 1].split("=", 1) for index, value in enumerate(args) if value == "-e")
        self.assertEqual(environment["DATABASE_URL"], environment["OUTBOX_TEST_DATABASE_URL"])
        self.assertEqual(environment["REDIS_URL"], environment["MESSENGER_TEST_REDIS_DSN"])
        self.assertEqual("test-only", environment["REDIS_PASSWORD"])
        self.assertIn("private-network", args)
        for flag in ("--mount", "-v", "--publish", "-p"):
            self.assertNotIn(flag, args)

    def test_native_app_has_no_network_and_is_privileged(self):
        with patch.object(self.runtime, "docker") as docker:
            self.runtime.create_app("native", privileged=True)
        args = docker.call_args_list[0].args
        self.assertEqual("none", args[args.index("--network") + 1])
        self.assertIn("--privileged", args)

    def test_timeout_still_attempts_to_preserve_lane_artifacts(self):
        run = Path(self.directory.name) / "run"
        run.mkdir()
        spec = {"files": ["tests/Unit/Test.php"], "services": False, "privileged": False}

        def timeout(*args, **kwargs):
            self.runtime.stopped.set()
            raise TimeoutError("test command expired")

        with patch.object(self.runtime, "create_app", return_value="app"), \
                patch.object(self.runtime, "docker"), \
                patch.object(self.runtime, "helper", side_effect=timeout), \
                patch.object(coordinator.subprocess, "run") as copy:
            with self.assertRaisesRegex(TimeoutError, "test command expired"):
                self.runtime.run_lane("ordinary", spec, run, False)
        self.assertEqual("docker", copy.call_args.args[0][0])
        self.assertEqual("cp", copy.call_args.args[0][1])
        self.assertLessEqual(copy.call_args.kwargs["timeout"], 30)

    def test_artifact_collection_timeout_preserves_original_lane_error(self):
        run = Path(self.directory.name) / "run"
        run.mkdir()
        spec = {"files": ["tests/Unit/Test.php"], "services": False, "privileged": False}
        with patch.object(self.runtime, "create_app", return_value="app"), \
                patch.object(self.runtime, "docker"), \
                patch.object(self.runtime, "helper", side_effect=TimeoutError("original lane timeout")), \
                patch.object(coordinator.subprocess, "run", side_effect=subprocess.TimeoutExpired(["docker", "cp"], 30)), \
                patch.object(coordinator.sys, "stderr", io.StringIO()):
            with self.assertRaisesRegex(TimeoutError, "original lane timeout"):
                self.runtime.run_lane("ordinary", spec, run, False)


class FinalStatusTest(unittest.TestCase):
    def test_successful_suites_report_failure_when_cleanup_fails(self):
        selected = {"files": {"tests/Unit/OnlyTest.php": ["testCase"]}, "coverage": False, "reports": {}}
        runtime = MagicMock()
        runtime.create_app.return_value = "discovery"
        runtime.helper.return_value = subprocess.CompletedProcess([], 0)
        runtime.run_lane.return_value = 0
        runtime.cleanup.return_value = False

        def copy(*args, **kwargs):
            if args[:2] == ("cp", "discovery:" + coordinator.RUN_DIRECTORY + "/."):
                (Path(args[2]) / "discovery.json").write_text(json.dumps(selected))

        runtime.docker.side_effect = copy
        with tempfile.TemporaryDirectory() as directory, \
                patch.dict(coordinator.os.environ, {"BAANDER_TEST_ARTIFACTS": directory, "BAANDER_TEST_PROCESSES": "2", "BAANDER_TEST_TIMEOUT": "3600", "BAANDER_TEST_REFERENCE": "0"}), \
                patch.object(coordinator, "Runtime", return_value=runtime) as constructor, \
                patch.object(coordinator.signal, "signal"), \
                patch.object(coordinator.sys, "stdout", io.StringIO()):
            self.assertEqual(1, coordinator.main([]))
            artifact_directory = constructor.call_args.args[0]
            self.assertEqual(1, json.loads((artifact_directory / "timing.json").read_text())["status"])
        runtime.run_lane.assert_called_once()
        runtime.cleanup.assert_called_once()


if __name__ == "__main__":
    unittest.main()
