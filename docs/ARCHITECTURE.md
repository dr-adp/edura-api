# EDURA Architecture

Backend:
Laravel 12 API

Database:
MySQL

Authentication:
Laravel Sanctum

Roles:

* Super Admin
* Institution Admin
* Teacher
* Student
* Parent

Permissions:
Spatie Permission Package

Design Rules:

* Full file replacements preferred
* Implement architecturally required features immediately
* Avoid technical debt
* Keep APIs role protected
* GitHub is source of truth


Architecture Milestone – Form Request Foundation Complete
All API controllers have been migrated to dedicated Laravel Form Requests.
A Service Layer has been introduced and will be expanded selectively for business-critical modules rather than every CRUD controller.
Future development will follow a business-module hardening approach, focusing on maintainability, transactions, authorization, events, performance, and testing before adding new features.

