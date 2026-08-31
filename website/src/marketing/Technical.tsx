import { Icon } from "../demo/Icon";

export function Technical() {
  return (
    <section className="technical-section" id="technical" aria-labelledby="technical-title">
      <div>
        <span className="section-number" aria-hidden="true">05</span>
        <p>For IT · Technical details</p>
        <h2 id="technical-title">Simple to run.<br /><em>Easy to adapt.</em></h2>
      </div>
      <div className="technical-copy">
        <p>Configure workflows, terminology, rounds, rooms, schedules, and role ownership around your university’s placement policy. Run it locally or on a modest PHP host with no mandatory external services.</p>
        <p className="technical-boundary">This website is a browser-only simulation. The full application runs on your university’s own infrastructure.</p>
        <div className="technical-ledger">
          <span>PHP 8.2–8.4</span><span>SQLite by default</span><span>PostgreSQL supported</span><span>Server-rendered HTML</span><span>Vanilla CSS + JavaScript</span><span>No mandatory external services</span><span>Portable CSV + JSON</span><span>Backups checked before restore</span>
        </div>
        <a className="button button-primary" href="https://github.com/agrim/campus-placement-engine/releases">Download the self-hosted release <Icon name="external" /></a>
      </div>
    </section>
  );
}
