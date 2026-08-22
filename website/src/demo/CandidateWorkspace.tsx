import type { Dispatch } from "react";
import {
  applicationsForCandidate,
  canSeeAccommodation,
  canSeePrivate,
  statusLabel,
  statusTone,
} from "./selectors";
import type { CompanyId, DemoAction, DemoSession } from "./types";
import { Icon } from "./Icon";

type Props = {
  session: DemoSession;
  dispatch: Dispatch<DemoAction>;
};

const companyScopes: CompanyId[] = ["atlas", "nova"];

export function CandidateWorkspace({ session, dispatch }: Props) {
  const candidate = session.world.candidates[session.selectedCandidateId];
  const applications = applicationsForCandidate(session, candidate.id);
  const showPrivate = canSeePrivate(session.activeRole);
  const showAccommodation = canSeeAccommodation(session.activeRole);

  return (
    <section className="candidate-workspace" aria-labelledby="candidate-workspace-title">
      <div className="candidate-summary">
        <div>
          <span className="section-kicker">Selected candidate</span>
          <h3 id="candidate-workspace-title">{candidate.name}</h3>
          <p>{candidate.program}</p>
        </div>
        <div className="current-location">
          <Icon name="location" size={18} />
          <span><small>Current location</small><strong>{candidate.location}</strong></span>
        </div>
        {candidate.id === "dev" && applications.length > 1 && !session.world.clashResolved ? (
          <span className="clash-flag"><Icon name="warning" size={20} /> Schedule clash</span>
        ) : null}
      </div>

      {session.activeRole === "company" ? (
        <div className="company-scope" aria-label="Company tracker scope">
          <span>Company scope</span>
          {companyScopes.map((companyId) => (
            <button
              type="button"
              key={companyId}
              aria-pressed={session.companyScope === companyId}
              className={session.companyScope === companyId ? "is-active" : ""}
              onClick={() => dispatch({ type: "set-company-scope", companyScope: companyId })}
            >
              {session.world.companies[companyId].name}
            </button>
          ))}
        </div>
      ) : null}

      <div className="opportunity-strip" aria-label={`${candidate.name} opportunities visible to this role`}>
        {applications.map((application) => {
          const company = session.world.companies[application.companyId];
          return (
            <article key={application.id}>
              <div><strong>{company.name}</strong><small>{application.round} · {application.room}</small></div>
              <time>{application.time}</time>
              <span className={`status-text status-${statusTone[application.status]}`}>{statusLabel[application.status]}</span>
            </article>
          );
        })}
      </div>

      {candidate.id === "dev" && session.activeRole !== "company" ? (
        <div className={`clash-card ${session.world.clashResolved ? "is-resolved" : ""}`}>
          <div>
            <span><Icon name={session.world.clashResolved ? "check" : "warning"} size={20} /></span>
            <div>
              <strong>{session.world.clashResolved ? "Both interviews protected" : "Two interviews overlap"}</strong>
              <p>
                {session.world.clashResolved
                  ? "Atlas stays at 11:15. Nova now starts safely at 11:50."
                  : "Atlas starts at 11:15. Nova starts five minutes later at 11:20."}
              </p>
            </div>
          </div>
          <dl>
            <div><dt>Atlas Systems</dt><dd>11:15</dd></div>
            <div><dt>Nova Labs</dt><dd>{session.world.applications["dev-nova"].time}</dd></div>
          </dl>
        </div>
      ) : null}

      <dl className="visibility-details">
        <div><dt>Accommodation logistics</dt><dd>{showAccommodation ? candidate.accommodation : <span className="masked"><Icon name="lock" size={15} /> Hidden for company teams</span>}</dd></div>
        <div><dt>Private placement context</dt><dd>{showPrivate ? candidate.privateTag : <span className="masked"><Icon name="lock" size={15} /> Hidden for this profile</span>}</dd></div>
      </dl>
    </section>
  );
}
