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

        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
        }

        .page {
            width: 1123px;
            height: 794px;
            padding: 36px;
            box-sizing: border-box;
            background: #f8fafc;
        }

        .certificate {
            width: 100%;
            height: 100%;
            border: 6px solid #111827;
            padding: 34px 46px;
            box-sizing: border-box;
            background: #ffffff;
            position: relative;
        }

        .inner-border {
            border: 2px solid #c9a227;
            height: 100%;
            padding: 26px 34px;
            box-sizing: border-box;
            position: relative;
        }

        .top {
            display: table;
            width: 100%;
        }

        .top-left,
        .top-center,
        .top-right {
            display: table-cell;
            vertical-align: middle;
        }

        .top-left {
            width: 22%;
            text-align: left;
        }

        .top-center {
            width: 56%;
            text-align: center;
        }

        .top-right {
            width: 22%;
            text-align: right;
            font-size: 10px;
            color: #4b5563;
        }

        .logo {
            max-height: 68px;
            max-width: 140px;
        }

        .institution {
            font-size: 23px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .title {
            text-align: center;
            margin-top: 34px;
            font-size: 38px;
            font-weight: bold;
            color: #111827;
        }

        .subtitle {
            text-align: center;
            margin-top: 14px;
            font-size: 15px;
            color: #374151;
        }

        .student {
            text-align: center;
            margin-top: 20px;
            font-size: 36px;
            font-weight: bold;
            color: #0f172a;
        }

        .student-line {
            width: 430px;
            border-top: 1px solid #9ca3af;
            margin: 8px auto 0;
        }

        .course-label {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #374151;
        }

        .course {
            text-align: center;
            margin-top: 8px;
            font-size: 24px;
            font-weight: bold;
        }

        .details {
            text-align: center;
            margin-top: 18px;
            font-size: 12px;
            line-height: 1.6;
            color: #374151;
        }

        .seal {
            position: absolute;
            left: 50%;
            bottom: 128px;
            transform: translateX(-50%);
            text-align: center;
        }

        .seal img {
            max-height: 76px;
            max-width: 76px;
        }

        .bottom {
            position: absolute;
            left: 34px;
            right: 34px;
            bottom: 42px;
            display: table;
            width: calc(100% - 68px);
        }

        .sign-box,
        .verify-box {
            display: table-cell;
            width: 33.33%;
            text-align: center;
            vertical-align: bottom;
            font-size: 11px;
        }

        .signature {
            max-height: 42px;
            max-width: 145px;
            margin-bottom: 4px;
        }

        .line {
            width: 180px;
            border-top: 1px solid #111827;
            margin: 0 auto 5px;
        }

        .qr svg {
            width: 68px;
            height: 68px;
        }

        .verify-text {
            margin-top: 3px;
            font-size: 9px;
            color: #4b5563;
        }

        .footer-text {
            position: absolute;
            left: 34px;
            right: 34px;
            bottom: 14px;
            text-align: center;
            font-size: 9px;
            color: #6b7280;
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="certificate">
            <div class="inner-border">

                <div class="top">
                    <div class="top-left">
                        @if (!empty($setting?->logo))
                            <img class="logo" src="{{ public_path('storage/' . $setting->logo) }}">
                        @elseif(!empty($certificate->course?->institution?->logo))
                            <img class="logo"
                                src="{{ public_path('storage/' . $certificate->course->institution->logo) }}">
                        @endif
                    </div>

                    <div class="top-center">
                        <div class="institution">
                            {{ $certificate->course->institution->name ?? 'AGHORI EDURA' }}
                        </div>
                    </div>

                    <div class="top-right">
                        Certificate No:<br>
                        <strong>{{ $certificate->certificate_number }}</strong>
                    </div>
                </div>

                <div class="title">
                    {{ $setting->certificate_title ?? 'Certificate of Completion' }}
                </div>

                <div class="subtitle">
                    {{ $setting->certificate_subtitle ?? 'This certificate is awarded to' }}
                </div>

                <div class="student">
                    {{ $certificate->studentProfile->user->name ?? 'Student Name' }}
                </div>
                <div class="student-line"></div>

                <div class="course-label">
                    for successfully completing the course
                </div>

                <div class="course">
                    {{ $certificate->course->title ?? 'Course Name' }}
                </div>

                <div class="details">
                    Final Percentage: <strong>{{ $certificate->final_percentage }}%</strong>
                    &nbsp; | &nbsp;
                    Final Grade: <strong>{{ $certificate->final_grade }}</strong>
                    &nbsp; | &nbsp;
                    Issued Date: {{ optional($certificate->issued_date)->format('d M Y') }}<br>
                    Verification Token: {{ $certificate->verification_token }}
                </div>

                @if (!empty($setting?->institution_seal))
                    <div class="seal">
                        <img src="{{ public_path('storage/' . $setting->institution_seal) }}">
                    </div>
                @endif

                <div class="bottom">
                    <div class="sign-box">
                        @if (!empty($setting?->signature_image))
                            <img class="signature" src="{{ public_path('storage/' . $setting->signature_image) }}">
                        @endif
                        <div class="line"></div>
                        <strong>{{ $setting->authorized_person_name ?? 'Authorized Signatory' }}</strong><br>
                        {{ $setting->authorized_person_designation ?? 'Institution Representative' }}
                    </div>

                    <div class="verify-box">
                        @if (($setting?->show_qr_code ?? true) && !empty($qrCodeSvg))
                            <div class="qr">{!! $qrCodeSvg !!}</div>
                            <div class="verify-text">Scan QR to verify certificate</div>
                        @endif
                    </div>

                    <div class="sign-box">
                        @if (!empty($setting?->secondary_signature_image))
                            <img class="signature"
                                src="{{ public_path('storage/' . $setting->secondary_signature_image) }}">
                        @endif
                        <div class="line"></div>
                        <strong>{{ $setting->secondary_signatory_name ?? 'Academic Head' }}</strong><br>
                        {{ $setting->secondary_signatory_designation ?? 'Verifier' }}
                    </div>
                </div>

                @if (!empty($setting?->footer_text))
                    <div class="footer-text">
                        {{ $setting->footer_text }}
                    </div>
                @endif

            </div>
        </div>
    </div>
</body>

</html>
