@if($getState())
    <img
        src="{{ route('admin.kyc-document', ['path' => str_replace('kyc-documents/', '', $getState())]) }}"
        alt="KYC документ"
        style="max-width: 100%; max-height: 400px; border-radius: 8px; border: 1px solid #e5e7eb;"
    />
@else
    <p style="color: #9ca3af;">Няма качен документ</p>
@endif
