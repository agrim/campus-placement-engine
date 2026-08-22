import type { Dispatch } from "react";
import { primaryApplication, statusLabel, statusTone, visibleCandidateIds } from "./selectors";
import type { DemoAction, DemoSession } from "./types";

type Props = {
  session: DemoSession;
  dispatch: Dispatch<DemoAction>;
  headingId: string;
};

export function CandidateQueue({ session, dispatch, headingId }: Props) {
  const candidateIds = visibleCandidateIds(session);

  return (
    <section className="candidate-queue" aria-labelledby={headingId}>
      <div className="panel-heading">
        <h3 id={headingId}>Candidate queue</h3>
        <span>{candidateIds.length} visible</span>
      </div>
      <div className="candidate-rows">
        {candidateIds.map((candidateId) => {
          const candidate = session.world.candidates[candidateId];
          const application = primaryApplication(session, candidateId);
          const company = session.world.companies[application.companyId];
          const selected = candidate.id === session.selectedCandidateId;
          return (
            <button
              type="button"
              key={candidate.id}
              className={`candidate-row ${selected ? "is-selected" : ""}`}
              aria-pressed={selected}
              onClick={() => dispatch({ type: "select-candidate", candidateId })}
            >
              <span className="candidate-avatar" aria-hidden="true">{candidate.initials}</span>
              <span className="candidate-name"><strong>{candidate.name}</strong><small>{candidate.program}</small></span>
              <span className="candidate-company"><strong>{application.time}</strong><small>{company.name}</small></span>
              <span className={`status-text status-${statusTone[application.status]}`}>{statusLabel[application.status]}</span>
            </button>
          );
        })}
      </div>
    </section>
  );
}
