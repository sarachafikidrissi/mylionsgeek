import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';

const STATUS_OPTIONS = [
  { value: 'pending', label: 'Pending' },
  { value: 'accepted', label: 'Accepted' },
  { value: 'refused', label: 'Refused' },
];

export default function StoryReportsIndex({ reports, filters }) {
  const status = filters?.status || 'pending';
  const rows = useMemo(() => (Array.isArray(reports?.data) ? reports.data : []), [reports?.data]);

  const resolve = (id, action) => {
    router.post(`/admin/story-reports/${id}/${action}`, {}, { preserveScroll: true });
  };

  return (
    <AppLayout>
      <Head title="Story reports" />
      <div className="space-y-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold text-foreground">Story reports</h1>
            <p className="mt-1 text-sm text-muted-foreground">Review reported Stories. Accepting hides the story from feeds.</p>
          </div>
          <div className="flex items-center gap-2">
            {STATUS_OPTIONS.map((opt) => (
              <Link
                key={opt.value}
                href={`/admin/story-reports?status=${opt.value}`}
                className={`rounded-full border px-4 py-2 text-sm font-semibold transition ${
                  status === opt.value
                    ? 'border-[var(--color-alpha)] bg-[var(--color-alpha)] text-black'
                    : 'border-[var(--color-border)] bg-card text-foreground hover:bg-muted/50'
                }`}
              >
                {opt.label}
              </Link>
            ))}
          </div>
        </div>

        <div className="rounded-xl border border-sidebar-border/70 bg-card">
          {rows.length === 0 ? (
            <div className="p-8 text-center text-sm text-muted-foreground">No reports.</div>
          ) : (
            <div className="divide-y divide-[var(--color-border)]">
              {rows.map((r) => (
                <div key={r.id} className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex min-w-0 flex-1 gap-3">
                    {r?.story?.media_url ? (
                      <a
                        href={r.story.media_url}
                        target="_blank"
                        rel="noreferrer"
                        className="h-16 w-16 shrink-0 overflow-hidden rounded-lg border border-[var(--color-border)] bg-muted"
                        title="Open media preview"
                      >
                        {r.story.media_type === 'video' ? (
                          <video src={r.story.media_url} className="h-full w-full object-cover" muted playsInline />
                        ) : (
                          <img src={r.story.media_url} alt="" className="h-full w-full object-cover" />
                        )}
                      </a>
                    ) : null}
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-foreground">
                        Story #{r?.story?.id ?? '—'} · {r?.story?.user?.name ?? 'Unknown'}
                        {r?.story?.audience === 'close_friends' ? (
                          <span className="ml-2 text-xs font-medium text-muted-foreground">close friends</span>
                        ) : null}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">{r.reason || 'No reason'}</p>
                      <p className="mt-1 text-xs text-muted-foreground">Reported by {r?.reporter?.name ?? 'Unknown'}</p>
                    </div>
                  </div>
                  {status === 'pending' ? (
                    <div className="flex gap-2">
                      <button
                        type="button"
                        className="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white"
                        onClick={() => resolve(r.id, 'accept')}
                      >
                        Hide story
                      </button>
                      <button
                        type="button"
                        className="rounded-lg bg-red-600 px-3 py-2 text-sm font-semibold text-white"
                        onClick={() => resolve(r.id, 'refuse')}
                      >
                        Refuse
                      </button>
                    </div>
                  ) : (
                    <span className="text-xs font-bold uppercase text-muted-foreground">{r.status}</span>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
