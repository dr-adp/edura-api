<!DOCTYPE html>

<html>

<head>
    <meta charset="utf-8">
    <title>Certificate</title>


    <style>
        @page {
            size: A4 landscape;
            margin: 12mm;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            margin: 0;
            padding: 0;
            color: #111827;
            font-size: 12px;
        }

        .certificate {
            width: 100%;
            border: 4px solid #111827;
            padding: 12px;
        }

        .inner-border {
            border: 2px solid #c9a227;
            padding: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .header td {
            vertical-align: middle;
        }

        .logo {
            max-height: 60px;
            max-width: 120px;
        }

        .institution {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
        }

        .certificate-no {
            text-align: right;
            font-size: 10px;
        }

        .title {
            text-align: center;
            font-size: 32px;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 5px;
        }

        .subtitle {
            text-align: center;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .student-name {
            text-align: center;
            font-size: 30px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 10px;
            margin-bottom: 5px;
        }

        .student-line {
            width: 55%;
            margin: 0 auto 10px auto;
            border-top: 1px solid #9ca3af;
        }

        .course-label {
            text-align: center;
            font-size: 14px;
        }

        .course-name {
            text-align: center;
            font-size: 22px;
            font-weight: bold;
            margin-top: 5px;
        }

        .details {
            text-align: center;
            font-size: 11px;
            margin-top: 10px;
            line-height: 1.5;
        }

        .seal-section {
            text-align: center;
            margin-top: 12px;
            margin-bottom: 12px;
        }

        .seal-image {
            height: 70px;
        }

        .signature-table {
            margin-top: 10px;
        }

        .signature-table td {
            width: 33.33%;
            text-align: center;
            vertical-align: bottom;
        }

        .signature-image {
            max-height: 45px;
            max-width: 140px;
        }

        .signature-line {
            width: 150px;
            border-top: 1px solid #111827;
            margin: 4px auto;
        }

        .sign-name {
            font-size: 11px;
            font-weight: bold;
        }

        .sign-designation {
            font-size: 10px;
        }

        .qr svg {
            width: 70px;
            height: 70px;
        }

        .qr-text {
            font-size: 9px;
        }

        .footer {
            text-align: center;
            margin-top: 10px;
            font-size: 9px;
            line-height: 1.4;
            color: #6b7280;
        }
    </style>


</head>

<body>


    <div class="certificate">

        <div class="inner-border">

            <table class="header">
                <tr>

                    <td width="20%">
                        @if (!empty($setting?->logo))
                            <img class="logo" src="{{ public_path('storage/' . $setting->logo) }}">
                        @elseif(!empty($certificate->course?->institution?->logo))
                            <img class="logo"
                                src="{{ public_path('storage/' . $certificate->course->institution->logo) }}">
                        @endif
                    </td>

                    <td width="60%">
                        <div class="institution">
                            {{ $certificate->course->institution->name ?? 'AGHORI EDURA' }}
                        </div>
                    </td>

                    <td width="20%">
                        <div class="certificate-no">
                            Certificate No.<br>
                            <strong>{{ $certificate->certificate_number }}</strong>
                        </div>
                    </td>

                </tr>
            </table>

            <div class="title">
                {{ $setting->certificate_title ?? 'Certificate of Completion' }}
            </div>

            <div class="subtitle">
                {{ $setting->certificate_subtitle ?? 'This certificate is awarded to' }}
            </div>

            <div class="student-name">
                {{ $certificate->studentProfile->user->name ?? 'Student Name' }}
            </div>

            <div class="student-line"></div>

            <div class="course-label">
                For successfully completing the course
            </div>

            <div class="course-name">
                {{ $certificate->course->title ?? 'Course Name' }}
            </div>

            <div class="details">
                Final Percentage:
                <strong>{{ $certificate->final_percentage }}%</strong>

                &nbsp; | &nbsp;

                Final Grade:
                <strong>{{ $certificate->final_grade }}</strong>

                &nbsp; | &nbsp;

                Issued Date:
                <strong>{{ optional($certificate->issued_date)->format('d M Y') }}</strong>
            </div>

            @if (!empty($setting?->institution_seal))
                <div class="seal-section">
                    <img class="seal-image" src="{{ public_path('storage/' . $setting->institution_seal) }}">
                </div>
            @endif

            <table class="signature-table">
                <tr>

                    <td>

                        @if (!empty($setting?->signature_image))
                            <img class="signature-image"
                                src="{{ public_path('storage/' . $setting->signature_image) }}">
                        @endif

                        <div class="signature-line"></div>

                        <div class="sign-name">
                            {{ $setting->authorized_person_name }}
                        </div>

                        <div class="sign-designation">
                            {{ $setting->authorized_person_designation }}
                        </div>

                    </td>

                    <td>

                        <div style="font-size:11px;font-weight:bold;">
                            VERIFIED CERTIFICATE
                        </div>

                        <div style="font-size:9px;margin-top:5px;">
                            Verification Token
                        </div>

                        <div style="font-size:10px;">
                            {{ $certificate->verification_token }}
                        </div>

                    </td>

                    <td>

                        @if (!empty($setting?->secondary_signature_image))
                            <img class="signature-image"
                                src="{{ public_path('storage/' . $setting->secondary_signature_image) }}">
                        @endif

                        <div class="signature-line"></div>

                        <div class="sign-name">
                            {{ $setting->secondary_signatory_name }}
                        </div>

                        <div class="sign-designation">
                            {{ $setting->secondary_signatory_designation }}
                        </div>

                    </td>

                </tr>
            </table>

            <div class="footer">

                Certificate UUID:
                {{ $certificate->certificate_uuid }}

                <br>

                Verification Status:
                {{ $certificate->verification_status }}

                @if (!empty($setting?->footer_text))
                    <br>
                    {{ $setting->footer_text }}
                @endif

            </div>

        </div>

    </div>


</body>

</html>
