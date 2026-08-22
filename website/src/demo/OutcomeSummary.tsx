import type { DemoSession } from "./types";
import { Icon } from "./Icon";

export function OutcomeSummary({ session }: { session: DemoSession }) {
  if (!session.world.placementCompanyId || !session.world.traceReviewed) return null;
  const company = session.world.companies[session.world.placementCompanyId];

  return (
    <section className="outcome-summary" aria-labelledby="outcome-title">
      <span className="outcome-check"><Icon name="check" size={30} /></span>
      <div>
        <span className="section-kicker">Mission complete</span>
        <h3 id="outcome-title">Dev attended both interviews and chose {company.name}.</h3>
        <p>No opportunity was missed. Every handoff and decision was recorded.</p>
      </div>
      <dl>
        <div><dt>{session.world.interviewsCompleted}</dt><dd>Interviews attended</dd></div>
        <div><dt>{session.world.clashResolved ? 1 : 0}</dt><dd>Clash resolved</dd></div>
        <div><dt>1</dt><dd>Placement recorded</dd></div>
      </dl>
    </section>
  );
}
