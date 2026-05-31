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
        'signature_image',
        'authorized_person_name',
        'authorized_person_designation',
        'footer_text',
        'status',
    ];

    protected $appends = [
        'logo_url',
        'signature_image_url',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    public function getSignatureImageUrlAttribute(): ?string
    {
        return $this->signature_image ? asset('storage/' . $this->signature_image) : null;
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
