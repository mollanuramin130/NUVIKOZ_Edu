"""Validate documentation/contracts/assets without external dependencies or app claims."""
from pathlib import Path
import csv
import hashlib
import json
import re

ROOT = Path(__file__).resolve().parents[1]

def require(condition, message):
    if not condition:
        raise AssertionError(message)

def main():
    required_paths = ['.editorconfig', '.gitattributes', '.github/workflows/repository-integrity.yml', '.gitignore', 'AGENTS.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'CURRENT_STATUS.md', 'README.md', 'apps/edu-api/README.md', 'apps/edu-mobile/README.md', 'apps/edu-web/README.md', 'assets/demo-samples/README.md', 'assets/demo-samples/admission-document.pdf', 'assets/demo-samples/assignment.pdf', 'assets/demo-samples/birth-certificate.pdf', 'assets/demo-samples/manifest.json', 'assets/demo-samples/payment-receipt.pdf', 'assets/demo-samples/report-card.pdf', 'assets/demo-samples/student-id-card.pdf', 'assets/demo-samples/transfer-certificate.pdf', 'docs/IMPORT_MANIFEST.json', 'docs/README.md', 'docs/agent-handoffs/CURRENT_STATUS.md', 'docs/api/API_STANDARDS.md', 'docs/api/ENDPOINT_CATALOG.csv', 'docs/architecture/ARCHITECTURE_v1.1.md', 'docs/architecture/MODULE_BOUNDARIES.md', 'docs/database/04_DATA_DICTIONARY.csv', 'docs/database/09_RELATIONSHIPS.csv', 'docs/database/09_RELATIONSHIPS_AND_FLOWS.md', 'docs/database/MIGRATION_GUIDELINES.md', 'docs/decisions/ARCHITECTURE_CHANGELOG.md', 'docs/decisions/GATE3_APPROVAL.md', 'docs/deployment/BRANCH_AND_CI_PLAN.md', 'docs/deployment/HOSTING_RESOURCE_IMPACT.md', 'docs/historical/00_OWNER_MASTER_PROMPT.txt', 'docs/historical/01_ARCHITECTURE.md', 'docs/historical/02_REQUIREMENTS_COVERAGE.csv', 'docs/historical/03_FEATURE_CONTRACTS.csv', 'docs/historical/04_DATA_DICTIONARY.csv', 'docs/historical/05_OPENAPI.json', 'docs/historical/06_TEST_MATRIX.csv', 'docs/historical/08_REVIEW_AND_VALIDATION.md', 'docs/historical/09_RELATIONSHIPS_AND_FLOWS.md', 'docs/historical/README.md', 'docs/historical/gate2_07_CURRENT_STATUS.md', 'docs/historical/gate2_14_REPOSITORY_CREATION_READINESS.md', 'docs/historical/gate2_16_CHANGE_SUMMARY_AND_APPROVAL.md', 'docs/historical/gate2_NUVIKOZ_Edu_Architecture_Review.html', 'docs/milestones/M1_FOUNDATION.md', 'docs/requirements/00_OWNER_CORRECTION_INSTRUCTIONS.txt', 'docs/requirements/02_REQUIREMENTS_COVERAGE.csv', 'docs/requirements/03_FEATURE_CONTRACTS.csv', 'docs/requirements/15_OWNER_CONFIRMED_REQUIREMENTS.md', 'docs/security/SECURITY_BASELINE.md', 'docs/security/STORAGE_POLICY_v1.1.md', 'docs/testing/06_TEST_MATRIX.csv', 'docs/testing/08_REVIEW_AND_VALIDATION.md', 'docs/testing/INITIALIZATION_REPORT.md', 'docs/testing/TESTING.md', 'docs/ui-ux/ROLE_FLOWS.md', 'packages/contracts/README.md', 'packages/contracts/openapi.json', 'packages/contracts/storage-policy-baseline.json', 'scripts/verify_repository.py', 'tests/README.md']
    for relative in required_paths:
        require((ROOT / relative).is_file(), "Missing initialized file: " + relative)
    imports = json.loads((ROOT / "docs/IMPORT_MANIFEST.json").read_text())["files"]
    for item in imports:
        path = ROOT / item["path"]
        require(path.is_file(), "Missing imported file: " + item["path"])
        require(hashlib.sha256(path.read_bytes()).hexdigest() == item["sha256"], "Import hash changed: " + item["path"])
    manifest = json.loads((ROOT / "assets/demo-samples/manifest.json").read_text())
    require(len(manifest["assets"]) == 7, "Expected seven shared samples")
    for asset in manifest["assets"]:
        path = ROOT / "assets/demo-samples" / asset["filename"]
        require(path.stat().st_size == asset["size_bytes"], "Sample byte mismatch")
        require(hashlib.sha256(path.read_bytes()).hexdigest() == asset["sha256"], "Sample hash mismatch")
        require(path.read_bytes().startswith(b"%PDF-"), "Sample not PDF")
    api = json.loads((ROOT / "packages/contracts/openapi.json").read_text())
    schemas = api["components"]["schemas"]
    refs = []
    operations = []
    def walk(value):
        if isinstance(value, dict):
            if "$ref" in value:
                refs.append(value["$ref"])
            for child in value.values():
                walk(child)
        elif isinstance(value, list):
            for child in value:
                walk(child)
    walk(api)
    require(all(ref.startswith("#/components/schemas/") and ref.rsplit("/", 1)[-1] in schemas for ref in refs), "Unresolved schema reference")
    for path, methods in api["paths"].items():
        for method, operation in methods.items():
            operations.append(operation["operationId"])
            for name in re.findall(r"{(\w+)}", path):
                require(any(p["name"] == name and p["in"] == "path" and p.get("required") for p in operation.get("parameters", [])), "Missing path parameter")
    require(len(operations) == len(set(operations)), "Duplicate API operation")
    policy = json.loads((ROOT / "packages/contracts/storage-policy-baseline.json").read_text())
    require(policy["demo"]["user_upload_bytes"] == 0 and policy["demo"]["user_file_count"] == 0, "Demo fixed guardrail changed")
    require(policy["free_live"] == {"max_pdf_bytes": 5000000, "max_active_uploaded_pdfs": 50, "daily_pdf_uploads": 10, "total_bytes": 500000000, "warning_percent": 80, "upload_block_percent": 100}, "Owner Free Live policy drift")
    require(policy["student_homework_uploads"] is False, "Student upload fixed guardrail changed")
    csv_count = 0
    for path in ROOT.rglob("*.csv"):
        with path.open(newline="") as stream:
            reader = csv.DictReader(stream)
            require(bool(reader.fieldnames), "CSV header missing")
            for row in reader:
                require(None not in row, "Malformed CSV: " + str(path.relative_to(ROOT)))
        csv_count += 1
    suspect = []
    patterns = [r"gh[pousr]_[A-Za-z0-9]{30,}", r"github_pat_[A-Za-z0-9_]{40,}", r"AKIA[0-9A-Z]{16}", "-----BEGIN " + r"(?:RSA |EC |OPENSSH )?PRIVATE KEY-----"]
    for path in ROOT.rglob("*"):
        if not path.is_file() or ".git" in path.parts:
            continue
        rel = str(path.relative_to(ROOT))
        require(not (path.name == ".env" or path.suffix in [".pem", ".key", ".jks", ".keystore", ".sql"]), "Sensitive file type: " + rel)
        if path.suffix != ".pdf":
            content = path.read_text(errors="replace")
            if any(re.search(pattern, content) for pattern in patterns):
                suspect.append(rel)
    require(not suspect, "Potential secrets: " + ",".join(suspect))
    print(json.dumps({"initialized_files": len(required_paths), "imported_files": len(imports), "samples": 7, "api_operations": len(operations), "schema_refs": len(refs), "csv_files": csv_count, "obvious_secret_patterns": "none", "application_tests": "NOT RUN"}))

if __name__ == "__main__":
    main()
