<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Certificate</title>

    <style>
        @page {
            margin: 0;
            size: A4 landscape;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
        }

        .page {
            width: 100%;
            height: 100%;
            padding: 25px;
            background-color: #f8fafc;

            @if (!empty($setting?->certificate_background))
                background-image: url("{{ public_path('storage/' . $setting->certificate_background) }}");
                background-size: cover;
                background-position: center;
                background-repeat: no-repeat;
            @endif
        }

        .certificate {
            width: 100%;
            min-height: 740px;
            border: 6px solid #111827;
            background: rgba(255, 255, 255, 0.96);
            padding: 18px;
        }

        .inner-border {
            width: 100%;
            min-height: 700px;
            border: 2px solid #c9a227;
            padding: 25px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: middle;
        }

        .logo {
            max-height: 80px;
            max-width: 150px;
        }

        .institution-name {
            text-align: center;
            font-size: 26px;
            font-weight: bold;
            color: #111827;
            letter-spacing: 1px;
        }

        .certificate-number {
            text-align: right;
            font-size: 11px;
            color: #374151;
        }

        .title {
            text-align: center;
            font-size: 40px;
            font-weight: bold;
            margin-top: 30px;
            color: #111827;
            letter-spacing: 2px;
        }

        .subtitle {
            text-align: center;
            font-size: 16px;
            margin-top: 15px;
            color: #4b5563;
        }

        .student-name {
            text-align: center;
            font-size: 34px;
            font-weight: bold;
            margin-top: 20px;
            color: #0f172a;
        }

        .student-line {
            width: 60%;
            margin: 10px auto;
            border-top: 1px solid #9ca3af;
        }

        .course-label {
            text-align: center;
            margin-top: 15px;
            font-size: 15px;
            color: #4b5563;
        }

        .course-name {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            margin-top: 10px;
            color: #111827;
        }

        .details {
            text-align: center;
            margin-top: 20px;
            font-size: 12px;
            line-height: 1.8;
            color: #374151;
        }

        .seal-section {
            text-align: center;
            margin-top: 25px;
            margin-bottom: 25px;
        }

        .seal-image {
            max-height: 90px;
            max-width: 90px;
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
            max-height: 55px;
            max-width: 170px;
        }

        .signature-line {
            width: 180px;
            border-top: 1px solid #111827;
            margin: 5px auto;
        }

        .signatory-name {
            font-size: 12px;
            font-weight: bold;
        }

        .signatory-designation {
            font-size: 11px;
            color: #4b5563;
        }

        .qr svg {
            width: 80px;
            height: 80px;
        }

        .qr-text {
            font-size: 10px;
            color: #4b5563;
            margin-top: 4px;
        }

        .footer {
            text-align: center;
            margin-top: 25px;
            font-size: 10px;
            color: #6b7280;
            line-height: 1.6;
        }

        .footer hr {
            border: none;
            border-top: 1px solid #d1d5db;
            margin-bottom: 10px;
        }
    </style>
</head>

<body>

    <div class="page">

        <div class="certificate">

            <div class="inner-border">

                {{-- HEADER --}}
                <table class="header-table">
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
                            <div class="institution-name">
                                {{ $certificate->course->institution->name ?? 'AGHORI EDURA' }}
                            </div>
                        </td>

                        <td width="20%">
                            <div class="certificate-number">
                                Certificate No.<br>
                                <strong>{{ $certificate->certificate_number }}</strong>
                            </div>
                        </td>

                    </tr>
                </table>

                {{-- TITLE --}}
                <div class="title">
                    {{ $setting->certificate_title ?? 'CERTIFICATE OF COMPLETION' }}
                </div>

                <div class="subtitle">
                    {{ $setting->certificate_subtitle ?? 'This certificate is proudly presented to' }}
                </div>

                {{-- STUDENT --}}
                <div class="student-name">
                    {{ $certificate->studentProfile->user->name ?? 'Student Name' }}
                </div>

                <div class="student-line"></div>

                {{-- COURSE --}}
                <div class="course-label">
                    For successfully completing the course
                </div>

                <div class="course-name">
                    {{ $certificate->course->title ?? 'Course Name' }}
                </div>

                {{-- DETAILS --}}
                <div class="details">

                    Final Percentage:
                    <strong>{{ $certificate->final_percentage }}%</strong>

                    &nbsp;&nbsp;|&nbsp;&nbsp;

                    Final Grade:
                    <strong>{{ $certificate->final_grade }}</strong>

                    &nbsp;&nbsp;|&nbsp;&nbsp;

                    Issued Date:
                    <strong>
                        {{ optional($certificate->issued_date)->format('d M Y') }}
                    </strong>

                    <br>

                    Verification Token:
                    <strong>{{ $certificate->verification_token }}</strong>

                </div>

                {{-- SEAL --}}
                @if (!empty($setting?->institution_seal))
                    <div class="seal-section">
                        <img class="seal-image" src="{{ public_path('storage/' . $setting->institution_seal) }}">
                    </div>
                @endif

                {{-- SIGNATURES + QR --}}
                <table class="signature-table">

                    <tr>

                        <td>

                            @if (!empty($setting?->signature_image))
                                <img class="signature-image"
                                    src="{{ public_path('storage/' . $setting->signature_image) }}">
                            @endif

                            <div class="signature-line"></div>

                            <div class="signatory-name">
                                {{ $setting->authorized_person_name ?? 'Authorized Signatory' }}
                            </div>

                            <div class="signatory-designation">
                                {{ $setting->authorized_person_designation ?? 'Institution Representative' }}
                            </div>

                        </td>

                        <td>

                            @if (($setting?->show_qr_code ?? true) && !empty($qrCodeSvg))
                                <div class="qr">
                                    {!! $qrCodeSvg !!}
                                </div>

                                <div class="qr-text">
                                    Scan QR Code to Verify
                                </div>
                            @endif

                        </td>

                        <td>

                            @if (!empty($setting?->secondary_signature_image))
                                <img class="signature-image"
                                    src="{{ public_path('storage/' . $setting->secondary_signature_image) }}">
                            @endif

                            <div class="signature-line"></div>

                            <div class="signatory-name">
                                {{ $setting->secondary_signatory_name ?? 'Academic Head' }}
                            </div>

                            <div class="signatory-designation">
                                {{ $setting->secondary_signatory_designation ?? 'Verifier' }}
                            </div>

                        </td>

                    </tr>

                </table>

                {{-- FOOTER --}}
                <div class="footer">

                    <hr>

                    Certificate UUID:
                    {{ $certificate->certificate_uuid }}

                    <br>

                    Verification Status:
                    {{ $certificate->verification_status }}

                    <br>

                    @if (!empty($setting?->verification_url))
                        Verify Online:
                        {{ $setting->verification_url }}
                    @endif

                    @if (!empty($setting?->footer_text))
                        <br>
                        {{ $setting->footer_text }}
                    @endif

                </div>

            </div>

        </div>

    </div>

</body>

</html>
