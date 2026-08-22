import type { Dispatch } from "react";
import { formatClock } from "./selectors";
import type { DemoAction, DemoSession } from "./types";

type Props = {
  session: DemoSession;
  dispatch: Dispatch<DemoAction>;
};

export function ActivityTrail({ session, dispatch }: Props) {
  const visibleEvents = session.activeRole === "company"
    ? session.world.audit.filter((event) => event.companyId === session.companyScope)
    : session.world.audit;
  const events = session.activityExpanded ? visibleEvents : visibleEvents.slice(0, 4);

  return (
    <section className="activity-trail" aria-labelledby="activity-title">
      <div className="panel-heading">
        <div><h3 id="activity-title">Activity trail</h3><span>Who changed what, and when</span></div>
        <button type="button" onClick={() => dispatch({ type: "toggle-activity" })}>
          {session.activityExpanded ? "Show latest" : `View all ${visibleEvents.length}`}
        </button>
      </div>
      <ol>
        {events.map((event) => (
          <li key={event.id} className={event.system ? "is-system" : ""}>
            <time>{formatClock(event.minute)}</time>
            <strong>{event.actor}</strong>
            <span>{event.message}</span>
          </li>
        ))}
        {events.length === 0 ? <li>No activity recorded for this company yet.</li> : null}
      </ol>
    </section>
  );
}
