import { Icon } from "../demo/Icon";

export function Trust() {
  return (
    <section className="trust-section" id="trust" aria-labelledby="trust-title">
      <div className="trust-lead">
        <span className="section-number" aria-hidden="true">03</span>
        <p>Student privacy</p>
        <h2 id="trust-title">Help candidates succeed. Keep their data <em>private.</em></h2>
        <p>Your university stays in control. Each person only sees what they need.</p>
      </div>
      <div className="privacy-proof">
        <div className="proof-header"><span>Company tracker view</span><span>Atlas Systems only</span></div>
        <div className="proof-person"><span className="candidate-avatar">DM</span><div><strong>Dev Malhotra</strong><small>Atlas Systems · Candidate requested</small></div></div>
        <dl>
          <div><dt>Program</dt><dd>Mechanical Engineering</dd></div>
          <div><dt>Current location</dt><dd>Waiting area 2</dd></div>
          <div><dt>Nova Labs route</dt><dd><span className="redaction"><Icon name="lock" size={15} /> Hidden from company teams</span></dd></div>
          <div><dt>Private placement context</dt><dd><span className="redaction"><Icon name="lock" size={15} /> Hidden from company teams</span></dd></div>
        </dl>
        <p>Sensitive candidate details stay out of company views.</p>
      </div>
      <div className="trust-rail">
        <article><span>01</span><div><h3>Only the right people can see or act</h3><p>Access follows each person’s role, company, and responsibilities.</p></div></article>
        <article><span>02</span><div><h3>Public results stay anonymous</h3><p>Placement totals never include candidate names or IDs.</p></div></article>
        <article><span>03</span><div><h3>Every update is accountable</h3><p>See who changed a candidate’s status, when it happened, and what changed.</p></div></article>
      </div>
      <p className="scope-note">Campus Placement Engine coordinates placement logistics and records outcomes. Hiring decisions remain with employers and candidates.</p>
    </section>
  );
}
