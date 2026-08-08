<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\InstitutionSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class InstitutionSettingsService
{
    public function listForInstitution(
        Institution|int $institution,
        ?string $group = null,
        bool $publicOnly = false
    ): Collection {
        $institutionId = $institution instanceof Institution
            ? $institution->id
            : $institution;

        return InstitutionSetting::query()
            ->where('institution_id', $institutionId)
            ->when($group, fn ($query) => $query->where('group', $group))
            ->when($publicOnly, fn ($query) => $query->public())
            ->active()
            ->orderBy('group')
            ->orderBy('key')
            ->get()
            ->map(function (InstitutionSetting $setting) {
                $setting->value = $this->readValue($setting);

                return $setting;
            });
    }

    public function get(
        Institution|int $institution,
        string $group,
        string $key,
        mixed $default = null
    ): mixed {
        $institutionId = $institution instanceof Institution
            ? $institution->id
            : $institution;

        $setting = InstitutionSetting::query()
            ->where('institution_id', $institutionId)
            ->forKey($group, $key)
            ->active()
            ->first();

        return $setting ? $this->readValue($setting) : $default;
    }

    public function set(array $data): InstitutionSetting
    {
        return DB::transaction(function () use ($data) {
            $isEncrypted = (bool) ($data['is_encrypted'] ?? false);

            $setting = InstitutionSetting::updateOrCreate(
                [
                    'institution_id' => $data['institution_id'],
                    'group' => $data['group'],
                    'key' => $data['key'],
                ],
                [
                    'value' => $this->writeValue($data['value'] ?? null, $isEncrypted),
                    'value_type' => $data['value_type'] ?? 'string',
                    'is_public' => $data['is_public'] ?? false,
                    'is_encrypted' => $isEncrypted,
                    'status' => $data['status'] ?? 'active',
                    'metadata' => $data['metadata'] ?? null,
                ]
            );

            $setting->value = $this->readValue($setting);

            return $setting->load('institution');
        });
    }

    public function update(
        InstitutionSetting $setting,
        array $data
    ): InstitutionSetting {
        return DB::transaction(function () use ($setting, $data) {
            $isEncrypted = array_key_exists('is_encrypted', $data)
                ? (bool) $data['is_encrypted']
                : (bool) $setting->is_encrypted;

            if (array_key_exists('value', $data)) {
                $data['value'] = $this->writeValue($data['value'], $isEncrypted);
            }

            $data['is_encrypted'] = $isEncrypted;

            $setting->update($data);
            $setting = $setting->fresh();
            $setting->value = $this->readValue($setting);

            return $setting->load('institution');
        });
    }

    public function delete(InstitutionSetting $setting): bool
    {
        return DB::transaction(
            fn (): bool => (bool) $setting->delete()
        );
    }

    private function writeValue(mixed $value, bool $isEncrypted): mixed
    {
        if (!$isEncrypted) {
            return $value;
        }

        return [
            'encrypted' => Crypt::encryptString(json_encode($value)),
        ];
    }

    private function readValue(InstitutionSetting $setting): mixed
    {
        if (!$setting->is_encrypted) {
            return $setting->value;
        }

        $encrypted = $setting->value['encrypted'] ?? null;

        return $encrypted
            ? json_decode(Crypt::decryptString($encrypted), true)
            : null;
    }
}
