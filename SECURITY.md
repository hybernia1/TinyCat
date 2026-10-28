# Security policy

## Supported versions

Security fixes target the latest release and the default branch. Upgrade to the latest release before reporting an issue.

## Reporting a vulnerability

Report vulnerabilities privately through [GitHub Security Advisories](https://github.com/hybernia1/TinyCat/security/advisories/new).
Include the affected version, reproduction steps, impact, and a minimal proof of concept. Remove credentials and personal data.
Please allow maintainers to investigate and coordinate a fix before public disclosure.

## Automated checks

Dependabot checks Composer dependencies and GitHub Actions weekly and proposes security updates. Quality gates run on pushes, pull requests, and weekly; they include PHPStan, dependency audit, repository security checks, and application tests. GitHub CodeQL scans JavaScript and GitHub Actions; PHP is checked by PHPStan and the repository security checks.
