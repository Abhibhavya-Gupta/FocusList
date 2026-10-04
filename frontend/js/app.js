(function ($) {
  var TOKEN_KEY = "dayline_token";
  var mode = "login";
  var todos = [];
  var filter = "today";
  var category = "all";

  function apiBase() {
    return (window.DAYLINE_API_BASE || "/api").replace(/\/$/, "");
  }

  function token() {
    return localStorage.getItem(TOKEN_KEY);
  }

  function request(path, options) {
    options = options || {};
    var headers = options.headers || {};
    headers["Content-Type"] = "application/json";
    if (token()) {
      headers.Authorization = "Bearer " + token();
    }
    return $.ajax({
      url: apiBase() + path,
      method: options.method || "GET",
      headers: headers,
      data: options.data ? JSON.stringify(options.data) : undefined,
      processData: false,
      dataType: "json"
    });
  }

  function showError(target, xhr, fallback) {
    var message = xhr && xhr.responseJSON && xhr.responseJSON.error;
    $(target).text(message || fallback);
  }

  function field(form, name) {
    return form.elements.namedItem(name);
  }

  function todayStr() {
    var d = new Date();
    var m = String(d.getMonth() + 1).padStart(2, "0");
    var day = String(d.getDate()).padStart(2, "0");
    return d.getFullYear() + "-" + m + "-" + day;
  }

  function escapeHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function isToday(todo) {
    return todo.due_date === todayStr() || (!todo.due_date && todo.status !== "done");
  }

  function isOverdue(todo) {
    return todo.status !== "done" && todo.due_date && todo.due_date < todayStr();
  }

  function visibleTodos() {
    return todos.filter(function (t) {
      if (category !== "all" && t.category !== category) return false;
      if (filter === "all") return true;
      if (filter === "done") return t.status === "done";
      if (filter === "doing") return t.status === "doing";
      if (filter === "open") return t.status === "open";
      if (filter === "overdue") return isOverdue(t);
      if (filter === "today") return isToday(t) && t.status !== "done";
      return true;
    });
  }

  function renderCats() {
    var set = {};
    todos.forEach(function (t) {
      if (t.category) set[t.category] = true;
    });
    var html = '<button class="filter' + (category === "all" ? " on" : "") + '" data-cat="all">All lists</button>';
    Object.keys(set).forEach(function (c) {
      html += '<button class="filter' + (category === c ? " on" : "") + '" data-cat="' + escapeHtml(c) + '">' + escapeHtml(c) + "</button>";
    });
    $("#cats").html(html);
  }

  function renderList() {
    var items = visibleTodos();
    var parents = items.filter(function (t) { return !t.parent_id; });
    var byParent = {};
    todos.forEach(function (t) {
      if (t.parent_id) {
        byParent[t.parent_id] = byParent[t.parent_id] || [];
        byParent[t.parent_id].push(t);
      }
    });
    if (!items.length) {
      $("#list").html('<p class="empty">Nothing here. Add one thing you can finish today.</p>');
      return;
    }
    var html = "";
    function row(t, child) {
      var chips = '<span class="chip">' + escapeHtml(t.category || "General") + "</span>";
      chips += '<span class="chip ' + escapeHtml(t.priority) + '">' + escapeHtml(t.priority) + "</span>";
      if (t.due_date) {
        chips += '<span class="chip' + (isOverdue(t) ? " overdue" : "") + '">' + escapeHtml(t.due_date) + (t.due_time ? " " + escapeHtml(t.due_time).slice(0, 5) : "") + "</span>";
      }
      html += '<article class="task' + (t.status === "done" ? " done" : "") + (child ? " child" : "") + '" data-id="' + t.id + '">';
      html += '<div class="task-top">';
      html += '<input class="check" type="checkbox"' + (t.status === "done" ? " checked" : "") + " />";
      html += '<div><div class="title">' + escapeHtml(t.title) + "</div><div class=\"meta\">" + chips + "</div></div>";
      html += '<div class="actions">';
      html += '<button class="btn tiny" data-act="doing">Start</button>';
      html += '<button class="btn tiny" data-act="break">Break down</button>';
      html += '<button class="btn tiny" data-act="edit">Edit</button>';
      html += '<button class="btn tiny" data-act="del">Delete</button>';
      html += "</div></div></article>";
      (byParent[t.id] || []).forEach(function (c) { row(c, true); });
    }
    parents.forEach(function (t) { row(t, false); });
    $("#list").html(html);
  }

  function stats() {
    var open = todos.filter(function (t) { return t.status !== "done" && !t.parent_id; }).length;
    var doneToday = todos.filter(function (t) { return t.status === "done" && !t.parent_id; }).length;
    var overdue = todos.filter(isOverdue).length;
    $("#stats").text(open + " open · " + doneToday + " finished · " + overdue + " overdue");
  }

  function loadTodos() {
    return request("/todos").done(function (res) {
      todos = res.todos || [];
      renderCats();
      renderList();
      stats();
    }).fail(function (xhr) {
      showError("#add-error", xhr, "Could not load your tasks");
    });
  }

  function showApp(user) {
    $("#auth-view").addClass("hidden");
    $("#app-view").removeClass("hidden");
    $("#hello").text("Hi, " + user.name.split(" ")[0]);
    var pretty = new Date().toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric" });
    $("#date-line").text(pretty);
    loadTodos();
  }

  function boot() {
    if (!token()) return;
    request("/me").done(function (res) {
      showApp(res.user);
    }).fail(function () {
      localStorage.removeItem(TOKEN_KEY);
    });
  }

  $("#toggle-auth").on("click", function () {
    mode = mode === "login" ? "register" : "login";
    $(".name-field").toggleClass("hidden", mode === "login");
    field($("#auth-form")[0], "name").required = mode === "register";
    field($("#auth-form")[0], "password").autocomplete = mode === "login" ? "current-password" : "new-password";
    $("#auth-submit").text(mode === "login" ? "Sign in" : "Create account");
    $(this).text(mode === "login" ? "Create an account" : "I already have an account");
  });

  $("#auth-form").on("submit", function (e) {
    e.preventDefault();
    $("#auth-error").text("");
    var data = {
      name: field(this, "name").value,
      email: field(this, "email").value,
      password: field(this, "password").value
    };
    request(mode === "login" ? "/login" : "/register", { method: "POST", data: data })
      .done(function (res) {
        localStorage.setItem(TOKEN_KEY, res.token);
        showApp(res.user);
      })
      .fail(function (xhr) {
        showError("#auth-error", xhr, "Could not sign in");
      });
  });

  $("#logout").on("click", function () {
    localStorage.removeItem(TOKEN_KEY);
    location.reload();
  });

  $("#composer").on("submit", function (e) {
    e.preventDefault();
    var text = $("#quick-add").val().trim();
    if (!text) return;
    $("#add-error").text("");
    $("#add-status").text("");
    request("/ai/parse", { method: "POST", data: { text: text, today: todayStr() } })
      .done(function (res) {
        var task = res.task;
        task.status = "open";
        request("/todos", { method: "POST", data: task })
          .done(function (created) {
            $("#quick-add").val("");
            $("#add-status").text("Added: " + created.todo.title + (created.todo.due_date ? " · due " + created.todo.due_date : ""));
            loadTodos();
          })
          .fail(function (xhr) {
            showError("#add-error", xhr, "Could not add task");
          });
      })
      .fail(function (xhr) {
        showError("#add-error", xhr, "Could not understand that task");
      });
  });

  $(document).on("click", ".filter[data-filter]", function () {
    filter = $(this).data("filter");
    $(".filter[data-filter]").removeClass("on");
    $(this).addClass("on");
    renderList();
  });

  $(document).on("click", "[data-cat]", function () {
    category = String($(this).data("cat"));
    renderCats();
    renderList();
  });

  $(document).on("change", ".check", function () {
    var id = $(this).closest(".task").data("id");
    var checkbox = this;
    var status = this.checked ? "done" : "open";
    request("/todos/" + id + "/status", { method: "PATCH", data: { status: status } })
      .done(loadTodos)
      .fail(function (xhr) {
        checkbox.checked = !checkbox.checked;
        showError("#add-error", xhr, "Could not update task");
      });
  });

  $(document).on("click", "[data-act]", function () {
    var act = $(this).data("act");
    var id = $(this).closest(".task").data("id");
    var todo = todos.filter(function (t) { return String(t.id) === String(id); })[0];
    if (act === "del") {
      if (!window.confirm("Delete this task?")) return;
      request("/todos/" + id, { method: "DELETE" }).done(loadTodos).fail(function (xhr) {
        showError("#add-error", xhr, "Could not delete task");
      });
    }
    if (act === "doing") {
      request("/todos/" + id + "/status", { method: "PATCH", data: { status: "doing" } })
        .done(loadTodos)
        .fail(function (xhr) { showError("#add-error", xhr, "Could not update task"); });
    }
    if (act === "break") {
      $("#coach-body").text("Breaking this into smaller steps…");
      request("/ai/breakdown", { method: "POST", data: { todo_id: id } }).done(function (res) {
        var steps = (res.breakdown && res.breakdown.steps) || [];
        $("#coach-body").html("<p>Steps for this task:</p><ol>" + steps.map(function (s) {
          return "<li>" + escapeHtml(s) + "</li>";
        }).join("") + "</ol>");
        loadTodos();
      }).fail(function (xhr) {
        showError("#coach-body", xhr, "Could not break down this task");
      });
    }
    if (act === "edit" && todo) {
      var form = $("#edit-form")[0];
      field(form, "id").value = todo.id;
      field(form, "title").value = todo.title;
      field(form, "notes").value = todo.notes || "";
      field(form, "category").value = todo.category || "General";
      field(form, "priority").value = todo.priority;
      field(form, "due_date").value = todo.due_date || "";
      field(form, "due_time").value = (todo.due_time || "").slice(0, 5);
      field(form, "status").value = todo.status;
      $("#edit-error").text("");
      $("#modal").removeClass("hidden");
    }
  });

  $("#cancel-edit").on("click", function () {
    $("#modal").addClass("hidden");
  });

  $("#edit-form").on("submit", function (e) {
    e.preventDefault();
    var form = this;
    var id = field(form, "id").value;
    $("#edit-error").text("");
    request("/todos/" + id, {
      method: "PUT",
      data: {
        title: field(form, "title").value,
        notes: field(form, "notes").value,
        category: field(form, "category").value,
        priority: field(form, "priority").value,
        due_date: field(form, "due_date").value || null,
        due_time: field(form, "due_time").value || null,
        status: field(form, "status").value
      }
    }).done(function () {
      $("#modal").addClass("hidden");
      loadTodos();
    }).fail(function (xhr) {
      showError("#edit-error", xhr, "Could not save task");
    });
  });

  $("#plan-btn").on("click", function () {
    $("#coach-body").text("Looking at your list…");
    request("/ai/plan", { method: "POST", data: {} }).done(function (res) {
      var plan = res.plan || {};
      var lines = (plan.plan || []).map(function (l) { return "<li>" + escapeHtml(l) + "</li>"; }).join("");
      $("#coach-body").html(
        "<p>" + escapeHtml(plan.headline || "Here is a simple day.") + "</p><ol>" + lines + "</ol><p>" + escapeHtml(plan.nudge || "") + "</p><small>Powered by " + escapeHtml(plan.provider || "local planning") + "</small>"
      );
    }).fail(function (xhr) {
      showError("#coach-body", xhr, "Could not plan your day");
    });
  });

  $("#suggest-btn").on("click", function () {
    $("#coach-body").text("Finding a helpful next step…");
    request("/ai/suggest", { method: "POST", data: {} }).done(function (res) {
      var ideas = (res.suggestions || []).map(function (idea) {
        return "<li>" + escapeHtml(idea) + "</li>";
      }).join("");
      $("#coach-body").html("<p>A few ideas to move things forward:</p><ul>" + ideas + "</ul><small>Powered by " + escapeHtml(res.provider || "local suggestions") + "</small>");
    }).fail(function (xhr) {
      showError("#coach-body", xhr, "Could not find suggestions");
    });
  });

  boot();
})(jQuery);
