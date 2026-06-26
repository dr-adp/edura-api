<?php

namespace Tests\Feature\Course;

use Tests\TestCase;

class CourseApiTest extends TestCase
{
    /** @test */
    public function example_test(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}