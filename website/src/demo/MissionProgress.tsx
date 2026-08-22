import type { Dispatch } from "react";
import { guidedSteps } from "./scenario";
import type { DemoAction, DemoSession } from "./types";
import { Icon } from "./Icon";

type Props = {
  session: DemoSession;
  dispatch: Dispatch<DemoAction>;
};

export function MissionProgress({ session, dispatch }: Props) {
  const completed = session.completedStepCount;
  const percent = Math.round((completed / guidedSteps.length) * 100);
  const remaining = guidedSteps.length - completed;

  return (
    <div className="mission-progress">
      <div className="mode-controls" aria-label="Demo mode">
        <button
          type="button"
          className={session.mode === "guided" ? "is-active" : ""}
          aria-pressed={session.mode === "guided"}
          onClick={() => dispatch({ type: "set-mode", mode: "guided" })}
        >
          <span className="live-dot" aria-hidden="true" /> Guided demo
        </button>
        <button
          type="button"
          className={session.mode === "free" ? "is-active" : ""}
          aria-pressed={session.mode === "free"}
          onClick={() => dispatch({ type: "set-mode", mode: "free" })}
        >
          Explore profiles
        </button>
        <button type="button" onClick={() => dispatch({ type: "reset" })}>
          Reset <Icon name="reset" size={16} />
        </button>
      </div>
      <div className="progress-row">
        <div className="progress-meter">
          <span>{completed === guidedSteps.length ? "Mission complete" : `Step ${completed + 1} of ${guidedSteps.length}`}</span>
          <div
            className="progress-track"
            role="progressbar"
            aria-label="Placement mission progress"
            aria-valuemin={0}
            aria-valuemax={guidedSteps.length}
            aria-valuenow={completed}
          >
            <i style={{ width: `${percent}%` }} />
          </div>
        </div>
        <dl className="outcome-counters">
          <div><dt>{session.world.clashResolved ? 2 : 1}</dt><dd>Opportunities protected</dd></div>
          <div><dt>{session.world.clashResolved ? 1 : 0}</dt><dd>Clashes resolved</dd></div>
          <div><dt>{remaining}</dt><dd>Actions remaining</dd></div>
        </dl>
        <p className="fictional-label"><strong>Fictional data</strong><span>Nothing is uploaded or stored.</span></p>
      </div>
    </div>
  );
}
