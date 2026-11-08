import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { api } from '@/lib/api';

type ReminderItem = { name: string; qty: number; serial?: string | null };
type Reminder = {
    id: number;
    reference_code: string;
    borrower_name: string;
    urgency: 'overdue' | 'due_today' | 'within_3_days';
    label: string;
    time_specified: boolean;
    items: ReminderItem[];
    staff_url: string;
    action: string;
    status: string;
};

type Snapshot = {
    upcoming_pickups: Reminder[];
    overdue_pickups: Reminder[];
    returns_due: Reminder[];
    overdue_returns: Reminder[];
    pending_approval: Reminder[];
};

const tone: Record<string, string> = {
    overdue: 'border-red-200 bg-red-50',
    due_today: 'border-amber-200 bg-amber-50',
    within_3_days: 'border-neutral-200 bg-white',
};

function CardList({ title, empty, cards, actionLabel }: { title: string; empty: string; cards: Reminder[]; actionLabel: string }) {
    return (
        <section className="rounded-lg border border-neutral-200 p-3">
            <h2 className="text-sm font-semibold">{title} <span className="font-normal text-neutral-500">({cards.length})</span></h2>
            {cards.length === 0 ? <p className="mt-2 text-xs text-neutral-500">{empty}</p> : (
                <ul className="mt-2 space-y-2">
                    {cards.map((card) => (
                        <li key={`${card.action}-${card.id}`} className={`rounded-md border p-2 text-sm ${tone[card.urgency] || ''}`}>
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <div className="font-mono text-xs">{card.reference_code}</div>
                                    <div>{card.borrower_name}</div>
                                    <div className="text-xs text-neutral-600">{card.label}</div>
                                    <ul className="mt-1 text-xs">
                                        {card.items.map((item) => <li key={item.name}>{item.name} × {item.qty}{item.serial ? ` · ${item.serial}` : ''}</li>)}
                                    </ul>
                                </div>
                                <Link className="shrink-0 text-xs font-medium text-lia-700 underline" to={card.staff_url}>{actionLabel}</Link>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export function OperationsReminders() {
    const { data } = useQuery({
        queryKey: ['operations-reminders'],
        queryFn: () => api<Snapshot>('/operations/reminders'),
        retry: false,
    });
    if (!data) {
        return null;
    }

    return (
        <div className="space-y-3">
            <div>
                <h2 className="text-base font-semibold">Next 3 days</h2>
                <p className="text-xs text-neutral-500">Africa/Kigali, rolling 72 hours. These stay until the equipment is handed out or checked in. Nothing is emailed or sent on WhatsApp from this list.</p>
            </div>
            <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                <CardList title="Upcoming pickups" empty="No approved hand-outs in the next 72 hours." cards={data.upcoming_pickups} actionLabel="Hand out" />
                <CardList title="Pickups overdue" empty="No approved hand-out is late." cards={data.overdue_pickups} actionLabel="Hand out" />
                <CardList title="Returns due" empty="No equipment is due back in the next 72 hours." cards={data.returns_due} actionLabel="Check in" />
                <CardList title="Returns overdue" empty="No equipment is late." cards={data.overdue_returns} actionLabel="Check in" />
            </div>
            <section className="rounded-lg border border-dashed border-neutral-300 p-3">
                <h2 className="text-sm font-semibold">Waiting for approval <span className="font-normal text-neutral-500">({data.pending_approval.length})</span></h2>
                <p className="text-xs text-neutral-500">Not a confirmed handover. Do not prepare these as approved.</p>
                {data.pending_approval.length === 0 ? <p className="mt-2 text-xs text-neutral-500">None in the next 72 hours.</p> : (
                    <ul className="mt-2 space-y-2">
                        {data.pending_approval.map((card) => (
                            <li key={card.id} className="flex items-center justify-between gap-2 text-sm">
                                <span><span className="font-mono text-xs">{card.reference_code}</span> {card.borrower_name} · {card.label} · {card.status.replace('_', ' ')}</span>
                                <Link className="text-xs text-lia-700 underline" to={card.staff_url}>Review</Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </div>
    );
}
