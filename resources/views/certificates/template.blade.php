<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Certificate</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            text-align: center;
            padding: 40px;
            color: #111827;
        }

        .certificate {
            border: 8px solid #111827;
            padding: 50px;
            height: 620px;
        }

        .brand {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .title {
            font-size: 38px;
            margin-top: 50px;
            font-weight: bold;
        }

        .subtitle {
            font-size: 18px;
            margin-top: 20px;
        }

        .student {
            font-size: 30px;
            font-weight: bold;
            margin-top: 35px;
        }

        .course {
            font-size: 24px;
            margin-top: 30px;
            font-weight: bold;
        }

        .details {
            margin-top: 35px;
            font-size: 15px;
        }

        .footer {
            margin-top: 70px;
            display: flex;
            justify-content: space-between;
            font-size: 14px;
        }

        .line {
            border-top: 1px solid #111827;
            width: 180px;
            margin: 0 auto 8px;
        }
    </style>
</head>

<body>
    <div class="certificate">
        <div class="brand">AGHORI EDURA</div>

        <div class="title">Certificate of Completion</div>

        <div class="subtitle">This certificate is proudly presented to</div>

        <div class="student">
            {{ $certificate->studentProfile->user->name ?? 'Student Name' }}
        </div>

        <div class="subtitle">for successfully completing the course</div>

        <div class="course">
            {{ $certificate->course->title ?? 'Course Name' }}
        </div>

        <div class="details">
            Final Percentage: {{ $certificate->final_percentage }}% <br>
            Final Grade: {{ $certificate->final_grade }} <br>
            Certificate No: {{ $certificate->certificate_number }} <br>
            Issued Date: {{ optional($certificate->issued_date)->format('d M Y') }}
        </div>

        <div class="footer">
            <div>
                <div class="line"></div>
                Student
            </div>

            <div>
                <div class="line"></div>
                Authorized Signature
            </div>
        </div>
    </div>
</body>

</html>
