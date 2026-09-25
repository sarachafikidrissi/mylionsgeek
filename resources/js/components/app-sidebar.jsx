import { NavMain } from '@/components/nav-main';
import Rolegard from '@/components/rolegard';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import {
    AwardIcon,
    Briefcase,
    Building2,
    Calendar,
    ClipboardList,
    Flag,
    FolderOpen,
    GraduationCap,
    LayoutGrid,
    Megaphone,
    MessageSquare,
    Monitor,
    Newspaper,
    Settings,
    Smartphone,
    Timer,
    UserPlus,
    Users,
    Wrench,
} from 'lucide-react';
import { useMemo } from 'react';
import AppLogo from './app-logo';

/** Roles that use the staff sidebar (students/coworkers use the top header instead). */
const STAFF_SIDEBAR_ROLES = ['admin', 'super_admin', 'moderateur', 'studio_responsable', 'coach', 'pro'];

const getRecruiterHiringNavItems = () => [
    {
        id: 'recruiter_dashboard',
        title: 'Dashboard',
        href: '/recruiter/dashboard',
        icon: LayoutGrid,
        authorizedRoles: ['recruiter'],
    },
    {
        id: 'recruiter_jobs',
        title: 'Jobs',
        href: '/recruiter/jobs',
        icon: Briefcase,
        authorizedRoles: ['recruiter'],
    },
    {
        id: 'recruiter_students',
        title: 'Students',
        href: '/recruiter/students',
        icon: GraduationCap,
        authorizedRoles: ['recruiter'],
    },
    {
        id: 'recruiter_applications',
        title: 'Applications',
        href: '/recruiter/applications',
        icon: ClipboardList,
        authorizedRoles: ['recruiter'],
    },
    {
        id: 'recruiter_interviews',
        title: 'Interview calendar',
        href: '/recruiter/interviews',
        icon: Calendar,
        authorizedRoles: ['recruiter'],
    },
    {
        id: 'recruiter_settings',
        title: 'Settings',
        href: '/settings',
        icon: Settings,
        authorizedRoles: ['recruiter'],
    },
];

const getDashboardNavItem = () => ({
    id: 'dashboard',
    title: 'Dashboard',
    href: '/admin/dashboard',
    icon: LayoutGrid,
});

const isCodingPro = (user, userRoles) => {
    const field = String(user?.field ?? '')
        .toLowerCase()
        .trim();

    return userRoles.includes('pro') && field === 'coding';
};

const canAccessProjects = (user, userRoles) => {
    if (userRoles.includes('admin')) {
        return true;
    }

    return isCodingPro(user, userRoles);
};

/** Pro + coding without elevated staff roles: limited sidebar (dashboard, members, projects) + feed. */
const isRestrictedCodingPro = (user, userRoles) => {
    if (!isCodingPro(user, userRoles)) {
        return false;
    }

    const elevatedStaff = ['admin', 'super_admin', 'moderateur', 'studio_responsable', 'coach'];

    return !userRoles.some((role) => elevatedStaff.includes(role));
};

const getManagementItems = (user, userRoles) => {
    const membersExcludedRoles = isCodingPro(user, userRoles) ? ['recruiter'] : ['recruiter', 'student'];

    return [
        {
            id: 'members',
            title: 'Members',
            href: '/admin/users',
            icon: Users,
            excludedRoles: membersExcludedRoles,
        },
        {
            id: 'training',
            title: 'Training',
            href: '/admin/training',
            icon: GraduationCap,
            authorizedRoles: ['admin', 'coach'],
        },
        {
            id: 'projects',
            title: 'Projects',
            href: '/admin/projects',
            icon: FolderOpen,
            authorizedRoles: ['admin', 'pro'],
        },
    ];
};

const getWorkItems = () => [
    {
        id: 'jobs',
        title: 'Jobs',
        href: '/admin/jobs',
        icon: Briefcase,
        authorizedRoles: ['admin'],
    },
    {
        id: 'organisations',
        title: 'Organisations',
        href: '/admin/organisations',
        icon: UserPlus,
        authorizedRoles: ['admin'],
    },
];

const getSpacesItems = () => [
    {
        id: 'spaces',
        title: 'Spaces',
        href: '/admin/places',
        icon: Building2,
        authorizedRoles: ['admin', 'studio_responsable'],
    },
    {
        id: 'reservations',
        title: 'Reservations',
        href: '/admin/reservations',
        icon: Timer,
        authorizedRoles: ['admin', 'studio_responsable'],
    },
    { id: 'appointments', title: 'Appointments', href: '/admin/appointments', icon: Calendar },
    {
        id: 'computers',
        title: 'Computers',
        href: '/admin/computers',
        icon: Monitor,
        authorizedRoles: ['admin', 'coach', 'moderateur'],
    },
    {
        id: 'equipment',
        title: 'Equipment',
        href: '/admin/equipements',
        icon: Wrench,
        authorizedRoles: ['admin', 'studio_responsable', 'moderateur'],
    },
];

const getCommunicationItems = () => [
    {
        id: 'announcements',
        title: 'App Notification',
        href: '/admin/announcements',
        icon: Megaphone,
        authorizedRoles: ['admin'],
    },
    {
        id: 'newsletter',
        title: 'Newsletter',
        href: '/admin/newsletter',
        icon: Newspaper,
        authorizedRoles: ['admin', 'coach'],
    },
];

const getGeneralItems = () => [
    {
        id: 'post_reports',
        title: 'Post Reports',
        href: '/admin/post-reports',
        icon: Flag,
        authorizedRoles: ['admin'],
    },
    {
        id: 'story_reports',
        title: 'Story Reports',
        href: '/admin/story-reports',
        icon: Flag,
        authorizedRoles: ['admin'],
    },
    {
        id: 'appversion',
        title: 'App Version',
        href: '/admin/appversion',
        icon: Smartphone,
        authorizedRoles: ['admin'],
    },
];

function RecruiterSidebarContext() {
    const { auth } = usePage().props;
    const recruiting = auth?.recruiting;

    if (!recruiting?.organization_name) {
        return null;
    }

    const isOwner = recruiting.membership_type === 'organisation_account';

    return (
        <div className="mx-2 mb-2 rounded-md border border-alpha/20 bg-alpha/5 px-3 py-2.5 group-data-[collapsible=icon]:hidden dark:border-light/10">
            <p className="truncate text-sm font-semibold text-beta dark:text-light">{recruiting.organization_name}</p>
            <p className="mt-0.5 text-xs text-beta/65 dark:text-light/65">
                {isOwner ? 'Organisation owner' : 'Team member'}
            </p>
        </div>
    );
}

// Check if user is one of the appointment persons
const isAppointmentPerson = (user) => {
    if (!user) return false;

    const personNames = ['Mahdi Bouziane', 'Hamid Boumehraz', 'Amina Khabab', 'Ayman Boujjar'];
    const emailMapping = {
        'mahdi.bouziane@lionsgeek.com': true,
        'hamid.boumehraz@lionsgeek.com': true,
        'amina.khabab@lionsgeek.com': true,
        'aymenboujjar12@gmail.com': true,
    };

    if (personNames.includes(user.name)) {
        return true;
    }

    if (user.email && emailMapping[user.email.toLowerCase()]) {
        return true;
    }

    return false;
};

export function AppSidebar() {
    const { auth } = usePage().props;
    const user = auth?.user;

    const userRoles = Array.isArray(user?.role) ? user.role : user?.role ? [user.role] : [];
    const isStaff = userRoles.some((r) => ['admin', 'moderateur', 'studio_responsable', 'coach', 'super_admin', 'pro'].includes(r));
    const isRecruiterOnlySidebar = userRoles.includes('recruiter') && !isStaff;
    const restrictedCodingPro = isRestrictedCodingPro(user, userRoles);

    const logoHref = isRecruiterOnlySidebar ? '/recruiter/dashboard' : '/admin/dashboard';

    const navGroups = useMemo(() => {
        if (isRecruiterOnlySidebar) return null;

        const spacesItems = getSpacesItems().filter((item) => {
            if (item.id === 'appointments') return isAppointmentPerson(user);
            return true;
        });

        return {
            dashboard: [getDashboardNavItem()],
            management: getManagementItems(user, userRoles).filter((item) => {
                if (item.id === 'projects') {
                    return canAccessProjects(user, userRoles);
                }

                if (restrictedCodingPro) {
                    return item.id === 'members';
                }

                return true;
            }),
            // community: getTraining(),
            spaces: spacesItems,
            work: getWorkItems(),
            communication: getCommunicationItems(),
            general: getGeneralItems(),
        };
    }, [user, userRoles, isRecruiterOnlySidebar, restrictedCodingPro]);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={logoHref} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {isRecruiterOnlySidebar && <RecruiterSidebarContext />}
            </SidebarHeader>

            <SidebarContent>
                {isRecruiterOnlySidebar ? (
                    <NavMain label="Hiring" items={getRecruiterHiringNavItems()} />
                ) : navGroups ? (
                    <>
                        <Rolegard authorized={STAFF_SIDEBAR_ROLES}>
                            <NavMain items={navGroups.dashboard} />
                        </Rolegard>
                        <NavMain items={navGroups.management} />
                        {/* <NavMain label="Community" items={navGroups.community} collapsible /> */}
                        {!restrictedCodingPro && (
                            <NavMain label="Spaces & Resources" labelIcon={Building2} items={navGroups.spaces} collapsible />
                        )}
                        {!restrictedCodingPro && (
                            <Rolegard authorized={['admin']}>
                                <NavMain label="Work" labelIcon={Briefcase} items={navGroups.work} collapsible />
                            </Rolegard>
                        )}
                        {!restrictedCodingPro && (
                            <Rolegard authorized={['admin', 'coach']}>
                                <NavMain
                                    label="Communication"
                                    labelIcon={MessageSquare}
                                    items={navGroups.communication}
                                    collapsible
                                />
                            </Rolegard>
                        )}
                        {!restrictedCodingPro && (
                            <Rolegard authorized={['admin']}>
                                <NavMain label="General" labelIcon={Settings} items={navGroups.general} collapsible />
                            </Rolegard>
                        )}
                    </>
                ) : null}
            </SidebarContent>

            <SidebarFooter>{/* <NavUser /> */}</SidebarFooter>
        </Sidebar>
    );
}
