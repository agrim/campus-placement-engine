import { Icon } from "../demo/Icon";

const pilotUrl = "https://github.com/agrim/campus-placement-engine/discussions/new?category=general";

export function Pilot() {
  return (
    <section className="pilot-section" id="pilot" aria-labelledby="pilot-title">
      <div className="pilot-heading">
        <span className="section-number" aria-hidden="true">06</span>
        <p>Try it with your placement team</p>
        <h2 id="pilot-title">See whether it helps more candidates <em>succeed.</em></h2>
      </div>
      <div className="pilot-copy">
        <p>Run a rehearsal with synthetic data, involve the people who manage placement day, and decide whether the Engine fits your university before introducing real candidate information.</p>
        <div className="pilot-actions">
          <a className="button button-primary" href={pilotUrl}>Discuss a university pilot <Icon name="external" /></a>
          <a className="text-link" href="https://github.com/agrim/campus-placement-engine/releases">Download the self-hosted release <Icon name="external" size={17} /></a>
        </div>
        <p className="pilot-note">The discussion is public. Do not include candidate details, credentials, or confidential university information.</p>
      </div>
    </section>
  );
}
