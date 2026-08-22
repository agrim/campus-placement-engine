import { journeyStages } from "./selectors";

type Props = {
  completedStepCount: number;
};

export function Journey({ completedStepCount }: Props) {
  return (
    <section className="journey-panel" aria-labelledby="journey-title">
      <div className="panel-heading">
        <h3 id="journey-title">Dev’s journey</h3>
        <span>One shared record</span>
      </div>
      <ol className="journey-list">
        {journeyStages.map((stage, index) => {
          const complete = completedStepCount >= stage.end;
          const active = completedStepCount >= stage.start && completedStepCount < stage.end;
          return (
            <li key={stage.label} className={`${complete ? "is-complete" : ""} ${active ? "is-active" : ""}`}>
              <span className="journey-marker" aria-hidden="true">{complete ? "✓" : index + 1}</span>
              <span><strong>{stage.label}</strong><small>{stage.detail}</small></span>
              <em>{complete ? "Complete" : active ? "Active" : "Upcoming"}</em>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
