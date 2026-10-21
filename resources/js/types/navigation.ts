import type { GlobalFeedbackFlash } from '../lib/globalFeedback'

export type ShellNavigationItem = {
    key: string
    label: string
    emoji?: string | null
    path: string
    route: string | null
    placeholder: boolean
    group?: boolean
    children: ShellNavigationItem[]
}

export type SafeUser = { id: number; name: string; email: string }

export type NotificationRelatedLink = { label: string; value: string; url: string }

export type ShellNotificationItem = {
    id: string
    category: string
    title: string
    message: string
    action_url: string | null
    entity_type: string | null
    entity_id: string | number | null
    actor_name: string | null
    related_links: NotificationRelatedLink[]
    read: boolean
    created_at: string | null
}

export type NotificationCenterSummary = { unread_count: number; items: ShellNotificationItem[] }

export type ShellPageProps = {
    auth: { user: SafeUser }
    shellNavigation: ShellNavigationItem[]
    notificationCenter: NotificationCenterSummary
    flash?: GlobalFeedbackFlash
}
