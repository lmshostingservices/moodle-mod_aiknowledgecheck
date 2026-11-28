# AI Knowledge Check and the EU AI Act

Version 1.8.0 (2026-11-28). Provider: LMS Hosting Services (LMS Labs), Perth, Western Australia.

This is the provider's technical analysis and instructions for use. It is not legal advice. Each school,
RTO or employer that runs the plugin should confirm its own position with its adviser.

## 1. What the plugin does

AI Knowledge Check (`mod_aiknowledgecheck`) is a Moodle activity. A teacher gives it topics, their own
questions or source text, and the AI drafts multiple-choice questions, answer keys, explanations, optional
pictures and optional voice narration. Learners then answer the questions. Answers are marked against the
fixed answer key the teacher approved. The AI never sees a learner's answers and never marks or grades a
learner.

The AI work runs on the LMS Labs service (credits) or, with Bring your own AI, on the site's own AI
provider. What is sent: the site id, the activity id, and what the teacher typed or pasted (topics, questions,
source text, audience settings, extra instructions). No learner names, answers, grades or attempts are sent.
Teachers should not paste personal information into source text.

## 2. Classification

| Use | Classification | Reason |
|---|---|---|
| Drafting questions, answer keys and explanations | Not high-risk under Annex III 3(b) on its intended purpose; Article 50 transparency applies | The AI writes an assessment instrument that a teacher checks and approves. It does not evaluate learning outcomes: marking is a fixed key, and the AI never sees learner work. Because the questions can count toward a grade, the plugin applies high-risk-style oversight anyway (section 4). |
| Pictures (AI image generation) | Article 50(2): machine-readable marking | Synthetic images. Not deepfakes of real people. |
| Voice narration (text to speech) | Article 50(2): machine-readable marking | Synthetic audio. Stock voices, not imitations of real people. |
| Survey mode | Article 50 transparency only | No marking or grading at all. |
| Prohibited practices (Article 5) | None | No emotion recognition, no proctoring, no behaviour monitoring, no profiling. |

Open point for each deployer: if graded results from this activity are used to decide admission, progression
or a qualification outcome, ask your adviser whether your use falls under Annex III 3. Human review does not by
itself take a system out of scope, so keep approval switched on.

## 3. Dates (checked 28 November 2026)

| Duty | Applies from |
|---|---|
| Prohibited practices; AI literacy (Article 4) | 2 February 2025 |
| General-purpose AI model duties (for model suppliers) | 2 August 2025 |
| Article 50 transparency (inform people, label content) | 2 August 2026 |
| Article 50(2) machine-readable marking, systems already on the market | 2 December 2026 |
| Annex III high-risk duties | 2 December 2027 |
| Annex I high-risk duties | 2 August 2028 |

Dates reflect Regulation (EU) 2024/1689 as amended by the Digital Omnibus on AI, Regulation (EU) 2026/1744
(in force 27 July 2026).

## 4. Who does what

**Provider (LMS Hosting Services):** builds transparency and oversight into the product (Articles 13, 14, 50),
marks AI content (Article 50(2)), keeps this document and the technical file current, monitors the product
after release and handles reports of serious problems.

**Deployer (your school, RTO or employer):** uses the plugin as described here (Article 26(1)), gives the
approval role to competent staff (26(2)), gives the AI accurate topics and source text (26(4)), watches for
problems and pauses the AI if needed (26(5)), keeps the logs for at least six months (26(6)), tells learners
about AI content (26(11), 50), and takes steps for staff AI literacy (Article 4).

**Model suppliers** (for example the providers behind the LMS Labs service or your own AI provider): general
purpose AI model duties under Articles 53 and 55.

## 5. Feature matrix

| Duty | Article | Feature in 1.8.0 |
|---|---|---|
| Human oversight before anything reaches learners | 14, 26(2) | **Review before learners see it** panel. With approval required (default on), learners cannot read or start AI-written questions until a named teacher ticks "I have checked every question, answer key and explanation" and approves. New AI output withdraws approval automatically. Server-side: `start_attempt` and `get_questions` refuse learners. Capability `mod/aiknowledgecheck:approve`. |
| Guard against automation bias | 14(4)(b) | Approval is disabled until the review is confirmed. Checklist of what to check. Oversight figures: questions written by AI, changed by a person, written by a person, and share changed on the report. |
| Understandable output | 14(4)(c), 13 | Every answer option carries the AI's explanation; the teacher edits them before approval. |
| Input control | 26(4) | The teacher supplies and can see the topics, source text and instructions used. |
| Suspend the system | 26(5) | **Pause AI on this site** (Site administration > Plugins > Activity modules > AI Knowledge Check > Responsible AI). Refuses all new AI work and logs the refusal; editing, approval and approved activities keep working. |
| Logs | 12, 26(6) | AI activity log per activity: generated, regenerated, picture, narration, edited, approved, withdrawn, refused while paused; who, when, and whether LMS Labs credits or the site's own AI was used. Kept for 1 year by default, never less than six months (scheduled task "Delete old AI activity log entries"). Shown on the attempts report. |
| Inform learners | 26(11), 50 | **AI-generated content** notice on the activity (default on), naming what AI made and who approved it, with a machine-readable marker (`data-ai-generated="true" data-ai-disclosure="eu-ai-act-art50"`). AI pictures carry a visible "AI-generated" label and alt text. |
| Machine-readable marking | 50(2) | Pictures: XMP with IPTC Digital Source Type `trainedAlgorithmicMedia` (JPEG APP1, PNG iTXt). Narration: Ogg Opus comments `AI_GENERATED=trainedAlgorithmicMedia` and `AI_GENERATOR`. Applied when content is stored. Page markup marks AI text. |
| AI literacy | 4 | **Using AI responsibly** panel in the review card: responsibility, limits, bias, data sent, how to report. |
| Privacy | GDPR | Privacy API covers the AI log and the approver; erasure anonymises log entries rather than deleting the oversight record. No learner data is sent to the AI. Google Fonts removed, so learners' browsers no longer contact Google to show the page. |

## 6. Instructions for use

**Intended purpose.** Drafting formative and summative knowledge-check questions and short surveys for
teachers and trainers, who check and approve them before learners see them.

**Not intended for.** Deciding admission, progression or certification on its own; assessing learners'
emotions or behaviour; proctoring; any use where approval is switched off and nobody checks the questions.

**Known limits.**
- The AI can mark the wrong option as correct, write an explanation that contradicts the answer, or state a
  rule that does not apply in your state, industry or training package.
- Questions can carry bias. Check wording for learners who speak English as a second language, for cultural
  assumptions, and for inclusive names and examples.
- Quality depends on the topics and source text. Thin or outdated source text gives thin or outdated questions.
- Machine-readable marks can be removed if a picture or audio file is edited elsewhere. WebP and GIF pictures and
  non-Ogg audio are labelled on the page but not marked inside the file.

**Human oversight steps.**
1. Generate or regenerate the questions.
2. Open Review Questions or Edit Questions and check every question, answer key and explanation, plus any
   picture and narration. Fix or delete anything wrong.
3. Tick the confirmation and select **Approve for learners**.
4. Watch the attempts report. If learners report a problem, select **Withdraw approval**, fix it, and approve
   again.

**Suspending the AI.** An administrator ticks **Pause AI on this site**. Report the problem to LMS Labs.

**Logs.** Set **Keep the AI activity log for** to match your records policy (at least six months).

**Telling learners.** Keep **Tell learners about AI-generated content** on. If your organisation must explain
an AI-assisted decision to a learner (Article 86), the AI log and the approval record show who checked what.

**Upgrading from 1.7.x.** Activities that learners could already see stay visible: their approval is carried
over (recorded as given before 1.8.0, with no name). New AI output in those activities needs approval as usual.
Activities created by AI Course Builder now also wait for a teacher's approval.

## 7. Provider work still outstanding

- Technical file (Article 11 and Annex IV style) for the LMS Labs service and the plugin together.
- Marking at source in the LMS Labs service (pictures and audio as generated), plus C2PA content credentials,
  so content is marked even outside Moodle.
- Obtain and file the general-purpose AI model suppliers' Article 53(1)(b) information for the models the LMS
  Labs service uses.
- Decide whether to sign the Code of Practice on Transparency of AI-Generated Content.
- Post-market monitoring: a support channel for AI problems and a serious-incident process (Articles 72, 73).
- Re-check classification against the final Commission guidelines on high-risk classification before
  2 December 2027.

## Sources

- Regulation (EU) 2024/1689 (AI Act), EUR-Lex: https://eur-lex.europa.eu/eli/reg/2024/1689/oj
- Regulation (EU) 2026/1744, Digital Omnibus on AI, in force 27 July 2026 (summary: White & Case,
  https://www.whitecase.com/insight-alert/eu-ai-omnibus-enters-force-amending-ai-act)
- Commission guidelines on Article 50 transparency, 20 July 2026, and the Code of Practice on Transparency of
  AI-Generated Content, 10 June 2026: https://digital-strategy.ec.europa.eu/en/faqs/code-practice-transparency-ai-generated-content
- IPTC Digital Source Type vocabulary: http://cv.iptc.org/newscodes/digitalsourcetype/
