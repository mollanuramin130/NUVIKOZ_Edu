"""Read-only hosted capability inventory; runtime acceptance is never inferred."""
import json
import os
import stat
import sys

status = dict(line.split(":", 1) for line in open("/proc/self/status") if ":" in line)
assert status["NoNewPrivs"].strip() == "1", "Privilege escalation must be disabled"
assert int(status["CapEff"].strip(), 16) == 0, "Effective capabilities must be zero"
assert int(status["CapBnd"].strip(), 16) == 0, "Capability bounding set must be empty"
print(json.dumps({"uid": os.getuid(), "gid": os.getgid(), "groups": os.getgroups(),
                  "NoNewPrivs": 1, "CapEff": 0, "CapBnd": 0}), flush=True)
print("No SDK/emulator/ADB command executed; guest network isolation NOT ESTABLISHED.", flush=True)
try:
    device = os.stat("/dev/kvm")
    print(json.dumps({"kvm_mode": oct(stat.S_IMODE(device.st_mode)),
                      "kvm_uid": device.st_uid, "kvm_gid": device.st_gid}), flush=True)
    fd = os.open("/dev/kvm", os.O_RDWR | os.O_CLOEXEC)
except OSError as error:
    print(json.dumps({"accelerated_runtime": "BLOCKED", "errno": error.errno,
                      "reason": error.strerror, "permissions_changed": False}), flush=True)
    sys.exit(1)
else:
    os.close(fd)
    print("Existing KVM device access available. No VM created.", flush=True)
    print("This preflight does not approve emulator networking or establish runtime PASS.", flush=True)
