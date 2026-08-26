import { Icon } from "../demo/Icon";

export function Hero() {
  return (
    <>
      <section className="hero" aria-labelledby="hero-title">
        <div className="hero-copy">
          <p className="hero-eyebrow">For university placement teams</p>
          <h1 id="hero-title" aria-label="Maximise job opportunities for your candidates.">Maximise job<br /><em>opportunities</em><span>for your candidates.</span></h1>
          <p>Prevent missed interviews. Resolve scheduling clashes. Help more candidates get placed.</p>
          <div className="hero-actions">
            <a className="button button-primary" href="#demo">Start the placement-day demo <Icon name="arrow" /></a>
            <a className="text-link" href="https://github.com/agrim/campus-placement-engine/releases">Download the self-hosted alpha <Icon name="external" size={17} /></a>
          </div>
        </div>

        <div className="hero-board" aria-label="Synthetic preview of Dev Malhotra’s scheduling clash">
          <div className="board-topline">
            <div><span>Control room</span><strong>One candidate has a scheduling clash</strong></div>
            <span className="live-status"><i aria-hidden="true" /> Demo ready</span>
          </div>
          <div className="board-stats">
            <div><strong>05</strong><span>Candidates</span></div>
            <div><strong>03</strong><span>Companies</span></div>
            <div><strong>01</strong><span>Clash to resolve</span></div>
          </div>
          <div className="clash-preview">
            <div className="preview-candidate">
              <span className="candidate-avatar">DM</span>
              <span><strong>Dev Malhotra</strong><small>Mechanical Engineering</small></span>
              <span className="preview-alert"><Icon name="warning" size={16} /> Schedule clash</span>
            </div>
            <div className="preview-opportunities">
              <div><span>Atlas Systems</span><strong>11:15</strong><small>Room A1</small></div>
              <b aria-label="conflicts with">vs</b>
              <div><span>Nova Labs</span><strong>11:20</strong><small>Room B2</small></div>
            </div>
          </div>
          <div className="board-footer">
            <span>Follow Dev across the placement team and protect both interviews.</span>
            <a href="#demo">Run the scenario <Icon name="arrow" size={16} /></a>
          </div>
        </div>
      </section>
    </>
  );
}
