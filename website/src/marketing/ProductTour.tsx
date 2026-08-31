export function ProductTour() {
  const demoRoot = `${import.meta.env.BASE_URL}demo`;

  return (
    <section className="product-tour" id="walkthrough" aria-labelledby="walkthrough-title">
      <div className="section-heading">
        <span className="section-number" aria-hidden="true">03</span>
        <div>
          <p>The real product</p>
          <h2 id="walkthrough-title">See a placement team turn a busy day into more candidate opportunities.</h2>
        </div>
      </div>
      <div className="product-tour-grid">
        <video
          controls
          playsInline
          preload="metadata"
          poster={`${demoRoot}/board-desktop.png`}
          aria-label="Campus Placement Engine product walkthrough"
        >
          <source src={`${demoRoot}/campus-placement-engine-demo.webm`} type="video/webm" />
          <track
            default
            kind="captions"
            src={`${demoRoot}/campus-placement-engine-demo.vtt`}
            srcLang="en"
            label="English"
          />
          Your browser does not support the walkthrough video.
        </video>
        <div className="product-tour-copy">
          <p>This is the working Engine with synthetic candidates—not a design mock-up.</p>
          <ul>
            <li>Spot urgent candidate needs and interview clashes.</li>
            <li>Focus each placement-team role on the right decisions.</li>
            <li>Keep records, readiness checks, and public results together.</li>
          </ul>
          <figure>
            <img src={`${demoRoot}/board-mobile.png`} alt="The live placement board reflowed for a phone-width screen" />
            <figcaption>The same essential actions remain available on a narrow screen.</figcaption>
          </figure>
        </div>
      </div>
    </section>
  );
}
