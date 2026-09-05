{{--
    SEC-16: one archived identity document. The image URL is id-addressed
    (route admin.kyc-retained) — the stored path is never echoed. Receives the
    KycRetention record and the kind (front|back|selfie) through the entry's
    view data.
--}}
@php
    $record = $getRecord();
    $kind = $kind ?? 'front';
    $path = $record?->documentPath($kind);
@endphp

@if ($record && $record->isPurged())
    <p style="color: #9ca3af;">Заличен на {{ $record->purged_at->timezone('Europe/Sofia')->format('d.m.Y H:i') }}</p>
@elseif ($record && $path)
    <img
        src="{{ route('admin.kyc-retained', ['retention' => $record->id, 'kind' => $kind]) }}"
        alt="KYC архив"
        style="max-width: 100%; max-height: 400px; border-radius: 8px; border: 1px solid #e5e7eb;"
    />
@else
    <p style="color: #9ca3af;">Няма документ</p>
@endif
