# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---
## [1.0.1] - 2025-08-07
### Added
 - Login page with username/password validation (configurable in config.php)
 - Logout functionality
 - Add Validate config

## [1.0.0] - 2025-08-07
### Added
- Initial public release of the project.
- File and folder listing via `listObjectsV2`.
- Folder creation via zero-byte object with trailing `/`.
- File uploads via standard POST form.
- File and folder deletion.
- File renaming using `copyObject` + `deleteObject`.
- Folder renaming (recursive prefix update).
- Presigned download URLs with 15 min expiry.
- Public URL generation via `public_base_url` config.
- Copy / Cut / Paste functionality with session-based clipboard.
- Simple Bootstrap 5 UI (loaded locally).
- `.gitignore` file added (vendor/, IDE, logs).
- `start.bat` and `open-vscode.bat` helper scripts.

---
