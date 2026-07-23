<?php

namespace Modules\Auth;

final class Rbac
{
    public const STAFF_INPUT = 'Staf Input';

    public const CANDIDATE_APPROVER = 'Approver Kandidat';

    public const ASSISTANT_MANAGER = 'Asisten Manajer';

    public const JOB_MANAGER = 'Manajer Job';

    public const SUPER_ADMIN = 'Super Admin';

    public const GUEST = 'Tamu';

    public const ROLES = [
        self::STAFF_INPUT,
        self::CANDIDATE_APPROVER,
        self::ASSISTANT_MANAGER,
        self::JOB_MANAGER,
        self::SUPER_ADMIN,
        self::GUEST,
    ];

    public const INTERNAL_ROLES = [
        self::STAFF_INPUT,
        self::CANDIDATE_APPROVER,
        self::ASSISTANT_MANAGER,
        self::JOB_MANAGER,
        self::SUPER_ADMIN,
    ];

    public const ROLE_PERMISSIONS = [
        self::STAFF_INPUT => [
            'candidate.create',
            'candidate.submit',
            'candidate.view',
            'lookup.request',
        ],
        self::CANDIDATE_APPROVER => [
            'candidate.review',
            'candidate.view',
        ],
        self::ASSISTANT_MANAGER => [
            'jobs.execute',
            'jobs.view',
            'placement.execute',
            'placement.view',
            'lookup.request',
            'company.request',
        ],
        self::JOB_MANAGER => [
            'jobs.review',
            'jobs.view',
            'placement.review',
            'placement.view',
        ],
        self::SUPER_ADMIN => [
            'audit.view',
            'candidate.anonymize',
            'candidate.view',
            'company.manage',
            'jobs.view',
            'lookup.manage',
            'placement.view',
            'users.assign_roles',
            'users.create',
            'users.deactivate',
            'users.reactivate',
            'users.reset_password',
            'users.update',
            'users.view',
        ],
        self::GUEST => [],
    ];

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::ROLE_PERMISSIONS))));
    }

    /**
     * @param  list<string>  $roles
     */
    public static function separationOfDutiesViolation(array $roles): ?string
    {
        if (in_array(self::GUEST, $roles, true)) {
            return 'USR_GUEST_ROLE';
        }

        if (self::containsAll($roles, [self::STAFF_INPUT, self::CANDIDATE_APPROVER])) {
            return 'USR_SOD_CANDIDATE';
        }

        if (self::containsAll($roles, [self::ASSISTANT_MANAGER, self::JOB_MANAGER])) {
            return 'USR_SOD_JOB';
        }

        if (in_array(self::SUPER_ADMIN, $roles, true) && array_intersect($roles, [
            self::STAFF_INPUT,
            self::CANDIDATE_APPROVER,
            self::ASSISTANT_MANAGER,
            self::JOB_MANAGER,
        ]) !== []) {
            return 'USR_SOD_SUPERADMIN';
        }

        return null;
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $needles
     */
    private static function containsAll(array $roles, array $needles): bool
    {
        return array_diff($needles, $roles) === [];
    }
}
