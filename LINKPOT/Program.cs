using System.Drawing;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Windows.Forms;

namespace AiTray
{
    static class Program
    {
        [STAThread]
        static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            using var app = new TrayApp();
            Application.Run(new ApplicationContext());
        }
    }

    class Config
    {
        public string Endpoint   = "http://192.168.1.86:4000/v1/chat/completions";
        public string Model      = "mini";
        public string ApiKey     = "";
        public int    MaxTokens  = 1024;
        public int    InputChars = 80;
        public int    InputLines = 5;
        public int    PromptX    = -1;
        public int    PromptY    = -1;
        public int    PromptW;
        public int    PromptH;
        public int    WindowX    = 100;
        public int    WindowY    = 100;
        public int    WindowW    = 600;
        public int    WindowH    = 400;
        public string WindowText = "";

        static readonly string CfgDir  = System.IO.Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "lpot");
        static readonly string CfgFile = System.IO.Path.Combine(CfgDir, "tray.cfg");

        public static Config Load()
        {
            var cfg = new Config();
            if (!File.Exists(CfgFile)) return cfg;
            try
            {
                var lines = File.ReadAllLines(CfgFile);
                bool inWindow = false;
                var sb = new StringBuilder();
                foreach (var line in lines)
                {
                    if (line == "--- WINDOW ---") { inWindow = true; continue; }
                    if (inWindow) { if (sb.Length > 0) sb.AppendLine(); sb.Append(line); continue; }
                    var eq = line.IndexOf('=');
                    if (eq < 0) continue;
                    var k = line.Substring(0, eq);
                    var v = line.Substring(eq + 1);
                    switch (k)
                    {
                        case "endpoint":     cfg.Endpoint   = v; break;
                        case "model":        cfg.Model      = v; break;
                        case "api_key":      cfg.ApiKey     = v; break;
                        case "max_tokens":   cfg.MaxTokens  = int.TryParse(v, out var mt) ? mt : 1024; break;
                        case "input_chars":  cfg.InputChars = int.TryParse(v, out var ic) ? ic : 80;   break;
                        case "input_lines":  cfg.InputLines = int.TryParse(v, out var il) ? il : 5;    break;
                        case "prompt_x":     cfg.PromptX    = int.TryParse(v, out var px) ? px : -1;   break;
                        case "prompt_y":     cfg.PromptY    = int.TryParse(v, out var py) ? py : -1;   break;
                        case "prompt_w":     cfg.PromptW    = int.TryParse(v, out var pw) ? pw : 0;    break;
                        case "prompt_h":     cfg.PromptH    = int.TryParse(v, out var ph) ? ph : 0;    break;
                        case "window_x":     cfg.WindowX    = int.TryParse(v, out var wx) ? wx : 100;  break;
                        case "window_y":     cfg.WindowY    = int.TryParse(v, out var wy) ? wy : 100;  break;
                        case "window_width":  cfg.WindowW   = int.TryParse(v, out var ww) ? ww : 600;  break;
                        case "window_height": cfg.WindowH   = int.TryParse(v, out var wh) ? wh : 400;  break;
                    }
                }
                cfg.WindowText = sb.ToString();
            }
            catch { }
            return cfg;
        }

        public void Save()
        {
            try
            {
                Directory.CreateDirectory(CfgDir);
                var sb = new StringBuilder();
                sb.AppendLine("endpoint=" + Endpoint);
                sb.AppendLine("model=" + Model);
                sb.AppendLine("api_key=" + ApiKey);
                sb.AppendLine("max_tokens=" + MaxTokens);
                sb.AppendLine("input_chars=" + InputChars);
                sb.AppendLine("input_lines=" + InputLines);
                sb.AppendLine("prompt_x=" + PromptX);
                sb.AppendLine("prompt_y=" + PromptY);
                sb.AppendLine("prompt_w=" + PromptW);
                sb.AppendLine("prompt_h=" + PromptH);
                sb.AppendLine("window_x=" + WindowX);
                sb.AppendLine("window_y=" + WindowY);
                sb.AppendLine("window_width=" + WindowW);
                sb.AppendLine("window_height=" + WindowH);
                sb.AppendLine("--- WINDOW ---");
                sb.AppendLine(WindowText);
                File.WriteAllText(CfgFile, sb.ToString());
            }
            catch { }
        }
    }
    class TrayApp : IDisposable
    {
        readonly NotifyIcon  notifyIcon;
        readonly HttpClient  http = new HttpClient();
        Form    resultForm;
        TextBox resultTextBox;
        Form    promptForm;       // <-- new
        bool    promptOpen;

        public TrayApp()
        {
            notifyIcon = new NotifyIcon
            {
                Icon = CreateIcon(),
                Text = "AI Completion",
                Visible = true
            };

            var menu = new ContextMenuStrip();
            menu.Items.Add("Quit", null, OnQuit);
            notifyIcon.ContextMenuStrip = menu;

            notifyIcon.MouseClick += (sender, e) =>
            {
                if (e.Button == MouseButtons.Left)
                    OnPrompt(sender, e);
            };
        }

        static Icon CreateIcon()
        {
            using (var bmp = new Bitmap(16, 16))
            {
                using (var g = Graphics.FromImage(bmp))
                {
                    g.Clear(Color.Transparent);
                    g.SmoothingMode = System.Drawing.Drawing2D.SmoothingMode.AntiAlias;
                    g.FillEllipse(Brushes.DodgerBlue, 2, 2, 12, 12);
                }
                return Icon.FromHandle(bmp.GetHicon());
            }
        }

        void PersistPrompt()                           // <-- new
        {
            if (promptForm == null) return;
            var c = Config.Load();
            c.PromptX = promptForm.Location.X;
            c.PromptY = promptForm.Location.Y;
            c.PromptW = promptForm.Width;
            c.PromptH = promptForm.Height;
            c.Save();
        }

        void OnPrompt(object sender, EventArgs e)
        {
            if (promptOpen) return;
            promptOpen = true;

            var cfg = Config.Load();

            var defaultW = Math.Max(300, Math.Min(1400, cfg.InputChars * 8));
            var defaultH = Math.Max(150, Math.Min(900, cfg.InputLines * 20 + 120));

            var form = new Form
            {
                Text = "AI Prompt",
                Size = new Size(cfg.PromptW > 0 ? cfg.PromptW : defaultW,
                                cfg.PromptH > 0 ? cfg.PromptH : defaultH),
                MinimizeBox = false,
                MaximizeBox = false
            };

            if (cfg.PromptX >= 0 && cfg.PromptY >= 0)
            {
                form.StartPosition = FormStartPosition.Manual;
                form.Location = new Point(cfg.PromptX, cfg.PromptY);
            }
            else
            {
                form.StartPosition = FormStartPosition.CenterScreen;
            }

            promptForm = form;                          // <-- new

            var info = new Label
            {
                Left = 10, Top = 8, Height = 18,
                Anchor = AnchorStyles.Top | AnchorStyles.Left | AnchorStyles.Right,
                Text = "Endpoint: " + cfg.Endpoint + "  Model: " + cfg.Model
            };

            var txt = new TextBox
            {
                Left = 10, Top = 30,
                Multiline = true,
                ReadOnly = false,
                ScrollBars = ScrollBars.Vertical,
                AcceptsReturn = true,
                AcceptsTab = true,
                Font = new Font("Consolas", 10F),
                BackColor = Color.White,
                Anchor = AnchorStyles.Top | AnchorStyles.Bottom | AnchorStyles.Left | AnchorStyles.Right
            };

            var sendBtn   = new Button { Text = "Send",   Size = new Size(75, 26), Anchor = AnchorStyles.Bottom | AnchorStyles.Right };
            var cancelBtn = new Button { Text = "Cancel", Size = new Size(75, 26), Anchor = AnchorStyles.Bottom | AnchorStyles.Right };

            form.Controls.Add(info);
            form.Controls.Add(txt);
            form.Controls.Add(sendBtn);
            form.Controls.Add(cancelBtn);

            EventHandler layout = delegate
            {
                var cw = form.ClientSize.Width;
                var ch = form.ClientSize.Height;
                info.Width = cw - 20;
                txt.Width  = cw - 20;
                txt.Height = ch - txt.Top - 40;
                cancelBtn.Location = new Point(cw - cancelBtn.Width - 10, ch - cancelBtn.Height - 8);
                sendBtn.Location   = new Point(cancelBtn.Left - sendBtn.Width - 5, cancelBtn.Top);
            };

            form.Load   += layout;
            form.Resize += layout;
            form.Shown  += delegate { txt.Focus(); };

            var ep     = cfg.Endpoint;
            var model  = cfg.Model;
            var key    = cfg.ApiKey;
            var maxTok = cfg.MaxTokens;

	    sendBtn.Click += async delegate
            {
                var prompt = txt.Text;
                if (string.IsNullOrWhiteSpace(prompt)) return;
                txt.Clear();

                string result;
                try   { result = await DoCompletion(ep, model, key, prompt, maxTok); }
                catch (Exception ex) { result = "Error: " + ex.Message; }
                ShowResultWindow(result);
            };

            cancelBtn.Click += delegate { form.Close(); };

            form.FormClosed += delegate
            {
                PersistPrompt();                       // <-- saves for every close path
                promptForm = null;                      // <-- new
                promptOpen = false;
            };

            form.Show();
        }

        async Task<string> DoCompletion(string endpoint, string model, string apiKey, string prompt, int maxTokens)
        {
            var body = JsonSerializer.Serialize(new
            {
                model = model,
                messages = new[] { new { role = "user", content = prompt } },
                max_tokens = maxTokens
            });

            var req = new HttpRequestMessage(HttpMethod.Post, endpoint)
            {
                Content = new StringContent(body, Encoding.UTF8, "application/json")
            };
            if (!string.IsNullOrEmpty(apiKey))
                req.Headers.Authorization = new AuthenticationHeaderValue("Bearer", apiKey);

            using (var resp = await http.SendAsync(req))
            {
                var data = await resp.Content.ReadAsStringAsync();
                if (!resp.IsSuccessStatusCode)
                    throw new Exception("HTTP " + (int)resp.StatusCode + ": " + data);
                try
                {
                    using (var doc = JsonDocument.Parse(data))
                    {
                        var root = doc.RootElement;
                        if (root.TryGetProperty("choices", out var choices) && choices.GetArrayLength() > 0)
                        {
                            var choice = choices[0];
                            if (choice.TryGetProperty("message", out var msg) &&
                                msg.TryGetProperty("content", out var content))
                                return content.GetString() ?? "";
                            if (choice.TryGetProperty("text", out var text))
                                return text.GetString() ?? "";
                        }
                    }
                }
                catch { }
                return data;
            }
        }

        void ShowResultWindow(string result)
        {
            var cfg = Config.Load();
            if (!string.IsNullOrEmpty(result))
                cfg.WindowText = result.Replace("\r\n", "\n").Replace("\n", "\r\n");

            if (resultForm != null)
            {
                PersistWindow();
                resultForm.Close();
                resultForm = null;
                resultTextBox = null;
            }

            resultForm = new Form
            {
                Text = "AI Result",
                StartPosition = FormStartPosition.Manual,
                Location = new Point(cfg.WindowX, cfg.WindowY),
                Size     = new Size(cfg.WindowW, cfg.WindowH)
            };

            resultTextBox = new TextBox
            {
                Dock = DockStyle.Fill,
                Multiline = true,
                ReadOnly = false,
                ScrollBars = ScrollBars.Both,
                WordWrap = false,
                Font = new Font("Consolas", 10F),
                BackColor = Color.White,
                Text = cfg.WindowText
            };

            resultForm.Controls.Add(resultTextBox);
            resultForm.FormClosed += delegate
            {
                PersistWindow();
                resultForm = null;
                resultTextBox = null;
            };
            resultForm.Show();
        }

        void PersistWindow()
        {
            if (resultForm == null || resultTextBox == null) return;
            var cfg = Config.Load();
            cfg.WindowX    = resultForm.Location.X;
            cfg.WindowY    = resultForm.Location.Y;
            cfg.WindowW    = resultForm.Width;
            cfg.WindowH    = resultForm.Height;
            cfg.WindowText = resultTextBox.Text;
            cfg.Save();
        }

        void OnQuit(object sender, EventArgs e)
        {
            PersistPrompt();                           // <-- new
            PersistWindow();
            notifyIcon.Visible = false;
            Application.Exit();
        }

        public void Dispose()
        {
            notifyIcon.Dispose();
            http.Dispose();
        }
    }
	

}
