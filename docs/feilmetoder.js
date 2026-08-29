  /**
   * Feilvakta.
   *
   * Bakgrunnen: nedtrekket for betalingsmaate sto tomt paa iPhone i dagevis
   * uten at noen visste det. Serverfeil gaar til feilloggen i cPanel, som
   * ingen leser. Feil i nettleseren gikk ingen steder i det hele tatt.
   *
   * Denne fanger tre ting av seg selv: unntak som kastes, lovnader som
   * ryker, og API-kall som svarer 500. Den fanger *ikke* en side som ser
   * riktig ut for maskinen og feil for mennesket — som nettopp det tomme
   * nedtrekket, der ingenting ble kastet. Det er «Meld inn feil» til for.
   */
  feilvaktStart() {
    if (this._feilvakt) return;
    this._feilvakt = true;
    this._feilSendt = {};
    this._feilAntall = 0;

    this._feilLytter = (e) => {
      // Et bilde eller et skript som ikke lot seg laste havner her ogsaa,
      // men uten melding — da staar elementet i e.target.
      const mal = e && e.target;
      if (mal && mal !== window && mal.tagName) {
        const url = String(mal.src || mal.href || '');
        // Bare vaare egne filer. At noen andres tjener er nede er ikke en
        // feil vi kan rette, og ville fylt lista med stoy.
        if (!url || url.indexOf(window.location.origin) !== 0) return;
        this.meldFeil({ feiltekst: 'Fikk ikke lastet ' + String(mal.tagName).toLowerCase(), kilde: url });
        return;
      }
      this.meldFeil({
        feiltekst: String((e && e.message) || 'Ukjent feil'),
        kilde: e && e.filename ? e.filename + ':' + e.lineno + ':' + e.colno : '',
      });
    };
    // Ressursfeil bobler ikke — de maa fanges paa vei ned.
    window.addEventListener('error', this._feilLytter, true);

    this._feilLovnad = (e) => {
      const g = e && e.reason;
      this.meldFeil({
        feiltekst: 'Ubehandlet: ' + String((g && g.message) || g || 'ukjent'),
        kilde: g && g.stack ? String(g.stack).split('\n').slice(1, 2).join('').trim() : '',
      });
    };
    window.addEventListener('unhandledrejection', this._feilLovnad);

    // Serverfeil.
    //
    // Kallene til API-et har ingen felles inngang i denne fila — de er
    // spredt paa mange steder. Da er fetch selv den felles inngangen.
    // Lofta gaar tilbake uendret; det som legges paa er en avstikker som
    // ser paa svaret. Vakta skal aldri kunne velte kallet den vokter.
    const opprinnelig = window.fetch;
    if (typeof opprinnelig === 'function' && !opprinnelig.__lissomvakt) {
      const vakt = (...arg) => {
        const svar = opprinnelig.apply(window, arg);
        try {
          const adresse = String((arg[0] && arg[0].url) || arg[0] || '');
          if (adresse.indexOf('/api/feil.php') === -1 && svar && svar.then) {
            svar.then((r) => {
              if (r && r.status >= 500) {
                this.meldFeil({ feiltekst: 'Serveren svarte ' + r.status, kilde: adresse });
              }
            }, () => {}).catch(() => {});
          }
        } catch (_) { /* stille */ }
        return svar;
      };
      vakt.__lissomvakt = true;
      window.fetch = vakt;
    }
  }

  feilvaktStopp() {
    if (this._feilLytter) window.removeEventListener('error', this._feilLytter, true);
    if (this._feilLovnad) window.removeEventListener('unhandledrejection', this._feilLovnad);
  }

  /**
   * Én automatisk rapport.
   *
   * Samme feil om og om igjen — en tegnesloyfe som kaster — skal sendes én
   * gang, ikke tusen. Og aldri mer enn fem fra én sideinnlasting: da er det
   * noe stort i stykker, og den femte forteller ikke noe den forste ikke
   * allerede har sagt.
   */
  meldFeil(data) {
    const tekst = String((data && data.feiltekst) || '').slice(0, 500);
    if (!tekst) return;
    const avtrykk = tekst + '|' + ((data && data.kilde) || '');
    if (this._feilSendt[avtrykk] || this._feilAntall >= 5) return;
    this._feilSendt[avtrykk] = true;
    this._feilAntall += 1;
    this.feilPost({
      slag: 'automatisk',
      feiltekst: tekst,
      kilde: String((data && data.kilde) || '').slice(0, 300),
    });
  }

  /** Selve kallet. Alt svelges — en feilmelder som feiler hoylytt er verre enn ingen. */
  feilPost(kropp) {
    const felles = {
      side: (window.location.pathname + window.location.search + window.location.hash).slice(0, 300),
      skjerm: window.innerWidth + '×' + window.innerHeight,
    };
    return fetch('/api/feil.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign(felles, kropp)),
    }).then(r => r.json()).catch(() => ({}));
  }

  /** Staar «Meld inn feil» paa, og hvor lenge til? */
  hentFeilbryter() {
    if (this._fmHentes) return;
    this._fmHentes = true;
    fetch('/api/feil.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(r => r.json())
      .then(d => { if (d && d.ok) this.setState({ fmApen: !!d.apen, fmTil: d.til || '' }); })
      .catch(() => {});
  }

  /** Det mennesket skrev. */
  feilSend() {
    if (this.state.fmSender) return;
    const tekst = String(this.state.fmTekst || '').trim();
    if (!tekst) {
      this.setState({ fmFeil: 'Skriv litt om hva som gikk galt, så vet vi hvor vi skal lete.' });
      return;
    }
    this.setState({ fmSender: true, fmFeil: '' });
    this.feilPost({
      slag: 'melding',
      melding: tekst.slice(0, 2000),
      kontakt: String(this.state.fmKontakt || '').trim().slice(0, 191),
      // Var det en feil rett for? Da folger den med, saa den som skal rette
      // slipper aa gjette hvilken av dem meldinga handler om.
      feiltekst: this._feilSist || '',
    }).then(d => {
      if (d && d.ok) {
        this.setState({
          fmDialog: false, fmTekst: '', fmKontakt: '', fmSender: false,
          kvittering: 'Takk — feilen er meldt inn',
          kvitteringDetalj: 'Verkstedet får den sammen med hvilken side og nettleser du var på.',
        });
      } else {
        this.setState({ fmSender: false, fmFeil: (d && d.feil) || 'Fikk ikke sendt. Prøv igjen om litt.' });
      }
    });
  }

  /** Verdiene til knappen og skjemaet. Ligger overalt, saa de er alltid med. */
  feilVals() {
    const s = this.state;
    const felt = {
      width: '100%', boxSizing: 'border-box', padding: '11px 14px',
      borderRadius: 'var(--radius-md)', border: '1.5px solid var(--border-subtle)',
      background: 'var(--surface-card)', font: 'var(--type-body)',
      fontSize: 'var(--text-base)', color: 'var(--text-heading)',
    };
    return {
      fmKnappVis: !!s.fmApen,
      fmVises: !!s.fmDialog,
      fmAapne: () => this.setState({ fmDialog: true, fmFeil: '' }),
      fmLukk: () => this.setState({ fmDialog: false, fmFeil: '' }),
      fmStopp: e => e.stopPropagation(),
      fmTekst: s.fmTekst || '',
      fmSkriv: e => this.setState({ fmTekst: e.target.value }),
      fmKontakt: s.fmKontakt || '',
      fmSkrivKontakt: e => this.setState({ fmKontakt: e.target.value }),
      fmSend: () => this.feilSend(),
      fmSendTekst: s.fmSender ? 'Sender …' : 'Send inn',
      fmHarFeil: !!s.fmFeil,
      fmFeil: s.fmFeil || '',
      fmFeltStil: felt,
      fmOmraadeStil: Object.assign({}, felt, { minHeight: 120, resize: 'vertical', lineHeight: 1.5 }),
      // Det tekniske folger med av seg selv. Si det, saa slipper folk aa
      // skrive «jeg bruker iPhone» — og saa de vet hva som sendes.
      fmTeknisk: 'Vi får automatisk med hvilken side du står på, nettleseren din og skjermstørrelsen.',
    };
  }

