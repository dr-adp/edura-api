<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Certificate</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            text-align: center;
            padding: 35px;
            color: #111827;
        }

        .certificate {
            border: 8px solid #111827;
            padding: 40px;
            height: 620px;
        }

        .logo {
            height: 75px;
            margin-bottom: 15px;
        }

        .brand {
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .title {
            font-size: 36px;
            margin-top: 35px;
            font-weight: bold;
        }

        .subtitle {
            font-size: 17px;
            margin-top: 18px;
        }

        .student {
            font-size: 30px;
            font-weight: bold;
            margin-top: 30px;
        }

        .course {
            font-size: 23px;
            margin-top: 25px;
            font-weight: bold;
        }

        .details {
            margin-top: 30px;
            font-size: 14px;
            line-height: 1.7;
        }

        .footer {
            margin-top: 55px;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
        }

        .signature {
            height: 50px;
            margin-bottom: 6px;
        }

        .line {
            border-top: 1px solid #111827;
            width: 190px;
            margin: 0 auto 8px;
        }

        .footer-text {
            margin-top: 25px;
            font-size: 11px;
            color: #4b5563;
        }
    </style>
</head>

<body>
    <div class="certificate">

        @if (!empty($setting?->logo))
            <img class="logo" src="{{ public_path('storage/' . $setting->logo) }}">
        @elseif(!empty($certificate->course?->institution?->logo))
            <img class="logo" src="{{ public_path('storage/' . $certificate->course->institution->logo) }}">
        @endif

        <div class="brand">
            {{ $certificate->course->institution->name ?? 'AGHORI EDURA' }}
        </div>

        <div class="title">
            {{ $setting->certificate_title ?? 'Certificate of Completion' }}
        </div>

        <div class="subtitle">
            {{ $setting->certificate_subtitle ?? 'This certificate is proudly presented to' }}
        </div>

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
                @if (!empty($setting?->signature_image))
                    <img class="signature" src="{{ public_path('storage/' . $setting->signature_image) }}">
                @endif

                <div class="line"></div>

                {{ $setting->authorized_person_name ?? 'Authorized Signature' }} <br>

                @if (!empty($setting?->authorized_person_designation))
                    {{ $setting->authorized_person_designation }}
                @endif
            </div>
        </div>

        @if (!empty($setting?->footer_text))
            <div class="footer-text">
                {{ $setting->footer_text }}
            </div>
        @endif

    </div>
</body>

</html>
