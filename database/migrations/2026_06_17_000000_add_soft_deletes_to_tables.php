<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'users',
            'institutions',
            'subscription_plans',
            'institution_subscriptions',
            'departments',
            'batches',
            'institution_users',
            'teacher_profiles',
            'student_profiles',
            'parent_profiles',
            'courses',
            'lessons',
            'course_enrollments',
            'assignments',
            'assignment_submissions',
            'assignment_evaluations',
            'question_banks',
            'question_options',
            'quizzes',
            'quiz_questions',
            'quiz_attempts',
            'quiz_answers',
            'gradebooks',
            'certificates',
            'certificate_settings',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'users',
            'institutions',
            'subscription_plans',
            'institution_subscriptions',
            'departments',
            'batches',
            'institution_users',
            'teacher_profiles',
            'student_profiles',
            'parent_profiles',
            'courses',
            'lessons',
            'course_enrollments',
            'assignments',
            'assignment_submissions',
            'assignment_evaluations',
            'question_banks',
            'question_options',
            'quizzes',
            'quiz_questions',
            'quiz_attempts',
            'quiz_answers',
            'gradebooks',
            'certificates',
            'certificate_settings',
        ];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
