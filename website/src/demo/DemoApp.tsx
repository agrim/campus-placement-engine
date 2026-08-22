import { useReducer } from "react";
import { ActionPanel } from "./ActionPanel";
import { ActivityTrail } from "./ActivityTrail";
import { CandidateQueue } from "./CandidateQueue";
import { CandidateWorkspace } from "./CandidateWorkspace";
import { Journey } from "./Journey";
import { MissionProgress } from "./MissionProgress";
import { OutcomeSummary } from "./OutcomeSummary";
import { RoleSwitcher } from "./RoleSwitcher";
import { demoReducer } from "./reducer";
import { createInitialSession } from "./scenario";

export function DemoApp() {
  const [session, dispatch] = useReducer(demoReducer, undefined, createInitialSession);

  return (
    <div className="demo-app">
      <MissionProgress session={session} dispatch={dispatch} />
      <RoleSwitcher session={session} dispatch={dispatch} />

      <div className="demo-canvas">
        <div className="queue-desktop"><CandidateQueue session={session} dispatch={dispatch} headingId="candidate-queue-desktop-title" /></div>
        <div className="demo-center">
          <CandidateWorkspace session={session} dispatch={dispatch} />
          <Journey completedStepCount={session.completedStepCount} />
        </div>
        <ActionPanel session={session} dispatch={dispatch} />
      </div>

      <details className="queue-mobile">
        <summary>View candidate queue</summary>
        <CandidateQueue session={session} dispatch={dispatch} headingId="candidate-queue-mobile-title" />
      </details>

      <OutcomeSummary session={session} />
      <ActivityTrail session={session} dispatch={dispatch} />
      <p className="demo-announcement" aria-live="polite">{session.announcement}</p>
      <p className="browser-only-note"><strong>Fictional data.</strong> This simulation runs only in your browser session. Nothing is uploaded or stored.</p>
    </div>
  );
}
