<?php

namespace Tests\Feature\Course;

use Tests\TestCase;

class CourseApiTest extends TestCase
{
    public function test_application_home_route_returns_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
