import type { Dispatch } from "react";
import { nextGuidedStep, nextRoleLabel, roleMatchesStep } from "./selectors";
import { roleConfig } from "./scenario";
import type { DemoAction, DemoSession } from "./types";
import { Icon } from "./Icon";

type Props = {
  session: DemoSession;
  dispatch: Dispatch<DemoAction>;
};

export function ActionPanel({ session, dispatch }: Props) {
  const step = nextGuidedStep(session);
  const role = roleConfig[session.activeRole];

  if (!step) {
    return (
      <section className="action-panel is-complete" aria-labelledby="action-panel-title">
        <span className="section-kicker">Mission complete</span>
        <h3 id="action-panel-title">Dev attended both interviews and chose Nova Labs.</h3>
        <p>No opportunity was missed. Every handoff and decision was recorded.</p>
        <button type="button" className="primary-action" onClick={() => dispatch({ type: "reset" })}>
          Replay the mission <Icon name="reset" />
        </button>
      </section>
    );
  }

  const canAct = roleMatchesStep(session, step) && session.selectedCandidateId === "dev";
  const requiredRole = nextRoleLabel(session, step);

  return (
    <section className="action-panel" aria-labelledby="action-panel-title">
      <div className="role-visibility">
        <span className="section-kicker">As {session.activeRole === "company" ? `${session.world.companies[session.companyScope].name} tracker` : role.label}, you can see</span>
        <ul>
          {role.visibility.map((item) => <li key={item}><Icon name="check" size={15} /> {item}</li>)}
        </ul>
      </div>

      <div className="next-action">
        <span className="section-kicker">Stage {step.act} of 6 · Your next action</span>
        <h3 id="action-panel-title">{step.title}</h3>
        <p>{step.instruction}</p>
        {canAct ? (
          <button type="button" className="primary-action" onClick={() => dispatch({ type: "run-current-step" })}>
            <span><Icon name={step.id === "resolve-clash" ? "swap" : "check"} /> {step.actionLabel}</span>
            <Icon name="arrow" />
          </button>
        ) : session.selectedCandidateId !== "dev" ? (
          <button type="button" className="primary-action" onClick={() => dispatch({ type: "select-candidate", candidateId: "dev" })}>
            <span>Select Dev to continue</span><Icon name="arrow" />
          </button>
        ) : (
          <>
            <div className="locked-action" aria-label={`${requiredRole} must complete the next action`}>
              <Icon name="lock" />
              <span><strong>{step.actionLabel}</strong><small>Only {requiredRole} can complete this step.</small></span>
            </div>
            <button
              type="button"
              className="switch-action"
              onClick={() => dispatch({
                type: "switch-role",
                role: step.role,
                companyScope: step.companyScope,
              })}
            >
              Continue as {requiredRole} <Icon name="arrow" />
            </button>
          </>
        )}
        <p className="candidate-benefit"><strong>Why it matters:</strong> {step.benefit}</p>
      </div>
    </section>
  );
}
