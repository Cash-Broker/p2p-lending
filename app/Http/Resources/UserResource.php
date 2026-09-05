<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'role' => $this->role,
            'kyc_status' => $this->kyc_status,
            'phone' => $this->phone,
            'account_type' => $this->account_type,
            'is_legal_entity' => $this->account_type === User::TYPE_LEGAL_ENTITY,
            // Company profile for legal-entity accounts. `eik`/`vat_number` are
            // decrypted by the model cast — exposing the owner's own company
            // identity back to them is fine. Only present once the relation is
            // eager-loaded and the row exists.
            'legal_entity_profile' => $this->when(
                $this->account_type === User::TYPE_LEGAL_ENTITY
                    && $this->relationLoaded('legalEntityProfile')
                    && $this->legalEntityProfile !== null,
                fn () => [
                    'legal_name' => $this->legalEntityProfile->legal_name,
                    'eik' => $this->legalEntityProfile->eik,
                    'legal_form' => $this->legalEntityProfile->legal_form,
                    'vat_number' => $this->legalEntityProfile->vat_number,
                    'address_country' => $this->legalEntityProfile->address_country,
                    'address_city' => $this->legalEntityProfile->address_city,
                    'address_postcode' => $this->legalEntityProfile->address_postcode,
                    'address_street' => $this->legalEntityProfile->address_street,
                    'company_email' => $this->legalEntityProfile->company_email,
                    'company_phone' => $this->legalEntityProfile->company_phone,
                ],
            ),
            'wallet' => new WalletResource($this->whenLoaded('wallet')),
            // SEC-22: the open deletion request, if any (null for every account today).
            // SEC-22: the live waiting period, so the SPA copy never hard-codes «7 дни».
            'deletion_waiting_days' => AccountDeletionService::waitingDays(),
            'deletion' => $this->deletion_requested_at === null && $this->deletion_finalized_at === null ? null : [
                'state' => $this->deletionState(),
                'requested_at' => $this->deletion_requested_at,
                'confirmed_at' => $this->deletion_confirmed_at,
                'scheduled_for' => $this->deletion_scheduled_for,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
