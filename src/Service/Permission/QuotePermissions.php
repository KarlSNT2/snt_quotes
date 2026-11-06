<?php

declare(strict_types=1);

namespace Snt\Quotes\Service\Permission;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;

/**
 * Droits fins sur les devis.
 * - lire / créer / modifier / supprimer : permissions natives de l'onglet AdminSntQuotes (Paramètres avancés > Permissions) ;
 * - valider une remise au-delà du seuil, convertir, voir la marge : profils choisis dans la configuration du module.
 * Le profil SuperAdmin a toujours tous les droits.
 */
class QuotePermissions
{
    public const TAB = 'AdminSntQuotes';

    public function __construct(private readonly ModuleConfig $config)
    {
    }

    public function canRead(): bool
    {
        return $this->tabAccess('view');
    }

    public function canCreate(): bool
    {
        return $this->tabAccess('add');
    }

    public function canEdit(): bool
    {
        return $this->tabAccess('edit');
    }

    public function canDelete(): bool
    {
        return $this->tabAccess('delete');
    }

    public function canApproveDiscount(): bool
    {
        return $this->inProfiles(ConfigKeys::PROFILES_APPROVE);
    }

    public function canConvert(): bool
    {
        return $this->canEdit() && $this->inProfiles(ConfigKeys::PROFILES_CONVERT);
    }

    public function canSeeMargin(): bool
    {
        return $this->inProfiles(ConfigKeys::PROFILES_SEE_MARGIN);
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'read' => $this->canRead(),
            'create' => $this->canCreate(),
            'edit' => $this->canEdit(),
            'delete' => $this->canDelete(),
            'approve_discount' => $this->canApproveDiscount(),
            'convert' => $this->canConvert(),
            'see_margin' => $this->canSeeMargin(),
        ];
    }

    private function employee(): ?\Employee
    {
        $employee = \Context::getContext()->employee ?? null;

        return $employee instanceof \Employee && \Validate::isLoadedObject($employee) ? $employee : null;
    }

    private function isSuperAdmin(): bool
    {
        $employee = $this->employee();

        return $employee !== null && (int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_;
    }

    private function tabAccess(string $right): bool
    {
        $employee = $this->employee();
        if (!$employee) {
            return false;
        }
        if ($this->isSuperAdmin()) {
            return true;
        }
        $access = \Profile::getProfileAccess((int) $employee->id_profile, (int) \Tab::getIdFromClassName(self::TAB));

        return !empty($access[$right]);
    }

    private function inProfiles(string $key): bool
    {
        $employee = $this->employee();
        if (!$employee) {
            return false;
        }

        return $this->isSuperAdmin() || in_array((int) $employee->id_profile, $this->config->getIntList($key), true);
    }
}
