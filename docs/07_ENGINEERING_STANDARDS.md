# EDURA ENGINNERINGN STANDARDS

> **Official Engineering Standards**
>
> This document defines the mandatory engineering, architecture, coding, testing, documentation, and release standards for the EDURA project.
>
> Every contributor must follow these standards to ensure consistency, maintainability, scalability, and production readiness.

---

# 1. Core Principles

Every line of code written for EDURA must satisfy at least one of the following objectives:

* Improve maintainability
* Improve readability
* Improve security
* Improve scalability
* Improve testability
* Improve performance

If a change satisfies none of these objectives, it should be reconsidered.

---

# 2. General Development Rules

* Follow PSR-12 coding standards.
* Follow Laravel 13 best practices.
* Never duplicate business logic.
* Prefer composition over duplication.
* Prefer refactoring by relocation instead of rewriting.
* Never modify working business logic without tests.
* Every feature must preserve backward compatibility unless intentionally breaking.

---

# 3. Controller Standards

Controllers must remain thin.

Controllers are responsible only for:

* Receiving requests
* Authorization
* Calling business logic
* Returning API responses

Controllers must never contain large validation blocks or complex business calculations.

---

# 4. Validation Standards

Validation must reside inside FormRequest classes.

Store operations:

* Store<Entity>Request

Update operations:

* Update<Entity>Request

Controllers must use:

$request->validated();

instead of inline validation.

---

# 5. API Response Standards

Every successful API response must use BaseApiController:

* successResponse()

Error responses should follow a common format and will be standardized project-wide during the exception-handling phase.

---

# 6. Testing Standards

No feature is considered complete until:

* Feature tests pass.
* Existing functionality remains unaffected.
* Regression is avoided.

Every significant refactoring must be followed by:

php artisan test

before committing.

---

# 7. Git Workflow

Each commit should represent one logical unit of work.

Preferred commit format:

Sprint XXX: <Description>

Examples:

Sprint 002: Refactor CourseController to use Form Requests

Sprint 003: Introduce API Resources for Course module

Avoid mixing unrelated changes into one commit.

---

# 8. Documentation Standards

Every development session should update:

* Project Master Log
* CTO Roadmap
* Changelog

Major architectural decisions should also be recorded.

---

# 9. Code Review Checklist

Before any commit, verify:

* Clean code
* No duplicated logic
* Naming consistency
* Tests passing
* Documentation updated
* No unnecessary complexity

---

# 10. Engineering Philosophy

Quality over speed.

Architecture over shortcuts.

Testing before merging.

Documentation before forgetting.

Consistency before cleverness.

EDURA is built for long-term maintainability, not short-term convenience.
