# EDURA Project Context

Project Name:
EDURA

Stack:
Laravel 12 API + Sanctum + Spatie Roles/Permissions

Architecture:
API only (no Blade)

Roles:
- super-admin
- institution-admin
- teacher
- student
- parent

Main Principle:
Every endpoint must enforce ownership and role-based access.

Security Priority:
1. super-admin
2. institution-admin (same institution only)
3. teacher (own courses only)
4. student (own records only)
5. parent (own child only)

Coding Rules:
- Never remove existing functionality.
- Preserve formatting.
- Return full files when changing controllers.
- Always add comments:

/*
|--------------------------------------------------------------------------
| Section Name
|--------------------------------------------------------------------------
*/

Use:
- hasAnyRole() for arrays
- eager loading
- route model binding
- JsonResponse
- abort(403) for unauthorized access

Project Status:

Completed:
✓ StudentDashboardController
✓ TeacherDashboardController
✓ ParentDashboardController
✓ AssignmentSubmissionController
✓ QuizAttemptController

Pending:
- QuizAnswerController
- GradebookController
- AttendanceController
- Course Progress
- Certificate Module
- Notifications
- Reports
- Analytics

Production Requirements:
- Multi-tenant
- Institution isolation
- Ownership checks everywhere
- No N+1 queries
- Soft deletes
- Laravel 12 conventions