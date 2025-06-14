<?php

declare(strict_types=1);

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Store\StoreResource;
use App\Enums\{UserStatus, UserRole};
use Spatie\Permission\Models\Permission;

/**
 * 使用者資源回應格式 (V2.0 - API 純數據原則)
 * 遵循 LomisX3 架構標準的統一 API 回應格式
 * 
 * ✅ V2.1 Enum 修復更新：
 * - 使用 $this->status->value 強制轉換 UserStatus Enum 為字串
 * - 修復 Laravel Enum 自動序列化為物件的問題
 * 
 * ✅ V2.0 重要更新：
 * - status 欄位簡化為純字串，移除複雜物件結構
 * - 移除 getStatusLabel() 和 getStatusColor() 方法
 * - 遵循 API 返回純數據，格式化邏輯交由前端處理的原則
 * 
 * 功能特色：
 * - 門市隔離資料過濾
 * - 敏感資料保護
 * - 角色權限相關資料
 * - 2FA 狀態顯示
 * - 媒體檔案處理
 */
class UserResource extends JsonResource
{
    /**
     * 轉換資源為陣列格式
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // 基本資訊
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            
            // 門市資訊
            'store_id' => $this->store_id,
            'store' => $this->whenLoaded('store', function () {
                return new StoreResource($this->store);
            }),
            
            // 聯絡資訊
            'phone' => $this->phone,
            
            // 狀態資訊 (V2.1 - 強制轉換為字串)
            'status' => $this->status->value, // ✅ 從 UserStatus Enum 取得原始字串值
            
            // 認證相關
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'is_email_verified' => !is_null($this->email_verified_at),
            
            // 2FA 資訊
            'two_factor' => [
                'enabled' => !is_null($this->two_factor_confirmed_at),
                'confirmed_at' => $this->two_factor_confirmed_at?->toISOString(),
            ],
            
            // 登入資訊
            'login_info' => [
                'last_login_at' => $this->last_login_at?->toISOString(),
                'last_login_ip' => $this->when(
                    $this->canViewSensitiveData($request),
                    $this->last_login_ip
                ),
                'login_attempts' => $this->when(
                    $this->canViewSensitiveData($request),
                    $this->login_attempts
                ),
                'is_locked' => $this->isLocked(),
                'locked_until' => $this->locked_until?->toISOString(),
            ],
            
            // 角色與權限
            'roles' => $this->getRoleNames(),
            
            // ✅ V4.2 SUPER ADMIN UI FIX: 修正權限宣告邏輯
            // 如果是 super_admin，則宣告擁有所有權限，以確保前端 UI 正確渲染
            // 如果是其他角色，則返回其被明確授予的權限
            'permissions' => $this->hasRole('super_admin') 
                ? Permission::all()->pluck('name')  // 返回系統中定義的所有權限
                : $this->getAllPermissions()->pluck('name'), // 維持原有邏輯
            
            'all_permissions' => $this->when(
                $request->has('include_all_permissions'),
                function () {
                    return $this->getAllPermissions()->pluck('name');
                }
            ),
            
            // 媒體檔案
            'avatar' => [
                'url' => $this->getAvatarUrl(),
                'thumbnail_url' => $this->getAvatarUrl('thumb'),
                'has_avatar' => $this->hasMedia('avatar')
            ],
            
            // 統計資訊
            'statistics' => $this->when(
                $request->has('include_statistics'),
                function () {
                    return [
                        'media_count' => $this->getMedia()->count(),
                        'tokens_count' => $this->tokens()->count(),
                        'activities_count' => $this->activities()->count(),
                        'created_users_count' => $this->when(
                            auth()->user()->can('users.view_statistics'),
                            function () {
                                return \App\Models\User::where('created_by', $this->id)->count();
                            }
                        )
                    ];
                }
            ),
            
            // 使用者偏好設定
            'preferences' => $this->when(
                $this->canViewPreferences($request),
                $this->preferences
            ),
            
            // 審計資訊
            'audit' => [
                'created_at' => $this->created_at->toISOString(),
                'updated_at' => $this->updated_at->toISOString(),
                'created_by' => $this->whenLoaded('createdBy', function () {
                    return [
                        'id' => $this->createdBy->id,
                        'name' => $this->createdBy->name,
                    ];
                }),
                'updated_by' => $this->whenLoaded('updatedBy', function () {
                    return [
                        'id' => $this->updatedBy->id,
                        'name' => $this->updatedBy->name,
                    ];
                }),
            ],
            
            // 可執行的操作
            'actions' => $this->getAvailableActions($request),
        ];
    }

    // ✅ V2.0 移除：getStatusLabel() 和 getStatusColor() 方法
    // 狀態格式化邏輯已轉移到前端，遵循 API 返回純數據的原則

    /**
     * 取得角色層級
     */
    private function getRoleLevel(string $roleName): int
    {
        return match ($roleName) {
            'admin' => 100,
            'store_admin' => 80,
            'manager' => 60,
            'staff' => 40,
            'guest' => 20,
            default => 0
        };
    }

    /**
     * 取得角色顏色
     */
    private function getRoleColor(string $roleName): string
    {
        return match ($roleName) {
            'admin' => 'red',
            'store_admin' => 'purple',
            'manager' => 'blue',
            'staff' => 'green',
            'guest' => 'gray',
            default => 'default'
        };
    }

    /**
     * 檢查是否為鎖定狀態
     */
    private function isLocked(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    /**
     * 取得頭像 URL
     */
    private function getAvatarUrl(string $conversion = ''): ?string
    {
        if (!$this->hasMedia('avatar')) {
            return null;
        }

        $media = $this->getFirstMedia('avatar');
        
        // 檢查媒體可見性
        if ($media->getCustomProperty('visibility') === 'private') {
            // 私有檔案需要簽名 URL
            return route('media.private', ['media' => $media->id]);
        }

        return $conversion ? $media->getUrl($conversion) : $media->getUrl();
    }

    /**
     * 檢查是否可以查看敏感資料
     */
    private function canViewSensitiveData(Request $request): bool
    {
        $currentUser = auth()->user();
        
        // 查看自己的資料
        if ($currentUser && $currentUser->id === $this->id) {
            return true;
        }
        
        // 管理員權限
        if ($currentUser && $currentUser->hasRole('admin')) {
            return true;
        }
        
        // 門市管理員查看同門市使用者
        if ($currentUser && 
            $currentUser->hasRole('store_admin') && 
            $currentUser->store_id === $this->store_id) {
            return true;
        }
        
        return false;
    }

    /**
     * 檢查是否可以查看偏好設定
     */
    private function canViewPreferences(Request $request): bool
    {
        $currentUser = auth()->user();
        
        // 只能查看自己的偏好設定
        return $currentUser && $currentUser->id === $this->id;
    }

    /**
     * 取得可執行的操作
     */
    private function getAvailableActions(Request $request): array
    {
        $currentUser = auth()->user();
        
        if (!$currentUser) {
            return [];
        }
        
        $actions = [];
        
        // 檢查各種操作權限
        if ($currentUser->can('users.update') && $this->canBeUpdated($currentUser)) {
            $actions[] = 'update';
        }
        
        if ($currentUser->can('users.delete') && $this->canBeDeleted($currentUser)) {
            $actions[] = 'delete';
        }
        
        if ($currentUser->can('users.reset_password') && $this->canResetPassword($currentUser)) {
            $actions[] = 'reset_password';
        }
        
        if ($this->canToggle2FA($currentUser)) {
            $actions[] = $this->two_factor_confirmed_at ? 'disable_2fa' : 'enable_2fa';
        }
        
        if ($currentUser->can('users.view_activities')) {
            $actions[] = 'view_activities';
        }
        
        return $actions;
    }

    /**
     * 檢查是否可以更新
     */
    private function canBeUpdated($currentUser): bool
    {
        // 門市隔離檢查
        if (!$currentUser->hasRole('admin') && $this->store_id !== $currentUser->store_id) {
            return false;
        }
        
        // 防止操作管理員帳號
        if ($this->hasRole('admin') && !$currentUser->hasRole('admin')) {
            return false;
        }
        
        return true;
    }

    /**
     * 檢查是否可以刪除
     */
    private function canBeDeleted($currentUser): bool
    {
        // 防止刪除自己
        if ($this->id === $currentUser->id) {
            return false;
        }
        
        // 防止刪除管理員
        if ($this->hasRole('admin')) {
            return false;
        }
        
        return $this->canBeUpdated($currentUser);
    }

    /**
     * 檢查是否可以重設密碼
     */
    private function canResetPassword($currentUser): bool
    {
        return $this->canBeUpdated($currentUser);
    }

    /**
     * 檢查是否可以切換 2FA
     */
    private function canToggle2FA($currentUser): bool
    {
        // 只能操作自己的 2FA 或有管理權限
        return $this->id === $currentUser->id || $this->canBeUpdated($currentUser);
    }
}
