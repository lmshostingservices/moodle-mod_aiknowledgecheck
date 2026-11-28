# AI Knowledge Check

An activity module for Moodle that generates knowledge checks and surveys from your course
content using AI, and reports on how learners respond.

Teachers supply topics, pasted source text, or their own questions. The activity generates
the questions and presents them to students one at a time, with immediate per-answer feedback
in quiz mode, or scale and free-text response collection in survey mode.

## Features

### Question generation

- Generate multiple-choice questions from topic names, pasted source text, or a transcript.
- Optionally supply your own questions instead of generating them.
- Set the number of questions generated per topic.
- Review and edit every question before students see it, including answer options and
  per-option explanations.
- Regenerate questions with revised instructions if the first result is not right.
- Optionally align questions to workplace or qualification context (education sector, VET or
  academic level, country and state, industry, and job role).

### Quiz mode

- Students answer one question at a time and check each answer as they go.
- Per-option explanations are shown after answering, so an incorrect choice becomes a
  teaching moment.
- Answer options are shuffled per attempt.
- Optional spoken explanations, with selectable language, voice gender and style.
- Configurable attempt limits, with per-user overrides for granting extra attempts.
- Grades are written to the Moodle gradebook, with an optional pass mark.

### Survey mode

- Collects opinions rather than testing knowledge: no correct answers and no score.
- Nine response scales — Agreement (4- and 5-point), Satisfaction, Frequency, Quality,
  Importance, Yes/No, Yes/No/Unsure, and NPS 5-point.
- Free-text questions can be mixed with scale questions, letting students type a response.
- Response distribution per question, plus CSV export of all responses.

### Content gates

Require students to engage with material before questions unlock:

- **Video gate** — a video must be watched first, with optional chapter timestamp links from
  each question back to the relevant moment.
- **Audio gate** — an audio clip must be played.
- **Image gate** — an image must be reviewed.

Gates can also be applied per question, so individual questions carry their own image, video
or audio.

### Reporting

- Attempts report showing each learner's attempts, score, time taken and completion time.
- Per-question breakdown of responses.
- CSV export for both attempts and survey responses.

### Responsible AI (EU AI Act)

- Teachers approve AI-written questions before learners see them; new AI output withdraws approval.
- Administrators can pause all new AI work on the site.
- Learners see an "AI-generated content" notice naming who approved the questions.
- AI pictures and narration are marked as AI-generated inside the file (IPTC `trainedAlgorithmicMedia`).
- An AI activity log (kept at least six months) and oversight figures on the attempts report.
- Instructions for use and the provider's analysis: [docs/eu-ai-act.md](docs/eu-ai-act.md).

### Design

- Built on Moodle Design System tokens and components (Button, Alert, Badge, Checkbox, Link), with the
  LMS Labs premium finish. Follows the theme's dark mode on Moodle 5.3.

## Requirements

- Moodle 4.0 or later. Version 1.8.0 was tested on Moodle 4.5, 5.2 and 5.3.
- An Essay Grader AI account and API key. Question generation is performed by an external
  service — see Privacy below.

## Installation

1. Install the ZIP through *Site administration → Plugins → Install plugins*, or copy the
   plugin folder to `mod/aiknowledgecheck` in your Moodle installation.
2. Visit *Site administration → Notifications* to complete the database upgrade.
3. Enter your API key at *Site administration → Plugins → Activity modules → AI Knowledge
   Check*.

## Usage

1. Add an **AI Knowledge Check** activity to a course.
2. Choose quiz mode or survey mode, and configure attempts, grading and any content gates.
3. Enter topics or source material, then generate the questions.
4. Review and edit the generated questions, then save.
5. Check every question, answer key and explanation, tick the confirmation and select
   **Approve for learners**.
6. Students complete the activity. Results appear in the Attempts report and, in quiz mode,
   in the gradebook.

## Privacy

The plugin stores attempt records containing the user ID, the responses given, and the start
and end times. Extra-attempt overrides are stored against the user ID.

Responsible AI records (v1.8.0): the AI activity log stores the user ID of the teacher or
administrator who generated, edited or approved questions, and the activity stores who approved
its questions. On an erasure request these entries are kept but no longer name the person.

Topic names and any source material supplied for question generation are sent to the Essay
Grader AI external service in order to generate questions. Student responses are **not** sent
to that service.

The plugin implements the Moodle Privacy API, so this data can be exported and deleted
through Moodle's standard privacy request tools.

## Capabilities

- `mod/aiknowledgecheck:addinstance` — add a new activity to a course.
- `mod/aiknowledgecheck:view` — view the activity.
- `mod/aiknowledgecheck:create` — generate and edit questions.
- `mod/aiknowledgecheck:approve` — approve AI-written questions for learners.
- `mod/aiknowledgecheck:viewreports` — view the attempts report.
- `mod/aiknowledgecheck:manageoverrides` — grant extra attempts to individual users.

## Licence

Copyright Essay Grader AI.

This program is free software: you can redistribute it and/or modify it under the terms of
the GNU General Public License as published by the Free Software Foundation, either version 3
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See
the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If
not, see <https://www.gnu.org/licenses/>.
