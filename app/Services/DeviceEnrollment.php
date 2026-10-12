<?php

namespace App\Services;

use App\Models\ClientToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * What happens to a device the moment it first appears (issues #97 and #95).
 *
 * - A default device group: new devices land in it instead of nowhere, so a
 *   role scoped to that group can see them without an admin filing each one.
 * - Approval by sign-in: with the approval gate on, a device whose RustDesk
 *   client is signed in to this console with a user account is approved on
 *   arrival and that user becomes its owner. Either order works: the device
 *   may register first and the user sign in later, or the reverse.
 */
class DeviceEnrollment
{
    public const SETTING_DEFAULT_GROUP = 'default_device_group_id';

    public const SETTING_AUTO_APPROVE = 'auto_approve_signed_in';

    /** The configured default group, or null when none is set or it no longer exists. */
    public static function defaultGroup(): ?DeviceGroup
    {
        $id = (int) Setting::get(self::SETTING_DEFAULT_GROUP, '0');

        return $id > 0 ? DeviceGroup::query()->find($id) : null;
    }

    public static function autoApproveSignedIn(): bool
    {
        return Setting::get(self::SETTING_AUTO_APPROVE, '0') === '1';
    }

    /**
     * Fill in what a first-seen device gets by default. Call before the row
     * is saved; returns the attributes to merge.
     *
     * @return array<string, mixed>
     */
    public static function defaultsForNew(array $attributes): array
    {
        if (empty($attributes['device_group_id']) && ($group = self::defaultGroup()) !== null) {
            $attributes['device_group_id'] = $group->id;
        }

        return $attributes;
    }

    /**
     * Approve a pending device because its client is signed in. With no
     * $user, the most recent valid client token issued from this device
     * decides. Returns true when the device was approved here.
     */
    public static function approveIfSignedIn(Device $device, ?User $user = null): bool
    {
        if (! $device->isPending() || ! self::autoApproveSignedIn()) {
            return false;
        }

        if ($user === null) {
            $token = ClientToken::query()
                ->where('device_id', $device->rustdesk_id)
                ->whereNotNull('user_id')
                ->latest('id')
                ->first();
            if ($token === null || ! $token->isValid()) {
                return false;
            }
            $user = $token->user;
            if ($user === null) {
                return false;
            }
        }

        if (! $user->is_active) {
            return false;
        }

        $changes = ['status' => Device::STATUS_ACTIVE];
        if ($device->user_id === null) {
            $changes['user_id'] = $user->id;
        }
        $device->update($changes);

        Log::info('Device approved on sign-in', ['device' => $device->rustdesk_id, 'user' => $user->username]);

        return true;
    }
}
