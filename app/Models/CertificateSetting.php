<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CertificateSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'institution_id',
        'certificate_title',
        'certificate_subtitle',
        'logo',
        'institution_seal',
        'certificate_background',
        'verification_url',
        'show_qr_code',
        'signature_image',
        'secondary_signature_image',
        'authorized_person_name',
        'authorized_person_designation',
        'secondary_signatory_name',
        'secondary_signatory_designation',
        'footer_text',
        'status',
    ];

    protected $casts = [
        'show_qr_code' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
        'institution_seal_url',
        'certificate_background_url',
        'signature_image_url',
        'secondary_signature_image_url',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    public function getInstitutionSealUrlAttribute(): ?string
    {
        return $this->institution_seal ? asset('storage/' . $this->institution_seal) : null;
    }

    public function getCertificateBackgroundUrlAttribute(): ?string
    {
        return $this->certificate_background ? asset('storage/' . $this->certificate_background) : null;
    }

    public function getSignatureImageUrlAttribute(): ?string
    {
        return $this->signature_image ? asset('storage/' . $this->signature_image) : null;
    }

    public function getSecondarySignatureImageUrlAttribute(): ?string
    {
        return $this->secondary_signature_image ? asset('storage/' . $this->secondary_signature_image) : null;
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
