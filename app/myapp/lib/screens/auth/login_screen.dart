import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../widgets/hand_drawn_widgets.dart';
import '../../theme/app_theme.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _nameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _nameController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    final auth = context.read<AuthProvider>();
    final success = await auth.login(
      _nameController.text.trim(),
      _passwordController.text.trim(),
    );
    if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(auth.error ?? '登录失败'),
          backgroundColor: HandDrawnTheme.accent,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return HandDrawnScaffold(
      showBackButton: false,
      body: Stack(
        children: [
          SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                children: [
                  const SizedBox(height: 60),
                  Text(
                    'ListenWrite',
                    style: TextStyle(
                      fontFamily: 'Kalam',
                      fontSize: 48,
                      fontWeight: FontWeight.w700,
                      color: HandDrawnTheme.pencil,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                    decoration: BoxDecoration(
                      color: HandDrawnTheme.postItYellow,
                      borderRadius: HandDrawnTheme.wobblyRadiusSm,
                      border: Border.all(color: HandDrawnTheme.pencil, width: 1),
                    ),
                    child: Text(
                      '班级默写 + 班级史记',
                      style: TextStyle(
                        fontFamily: 'Patrick Hand',
                        fontSize: 16,
                        color: HandDrawnTheme.pencil,
                      ),
                    ),
                  ),
                  const SizedBox(height: 60),
                  HandDrawnInput(
                    label: '用户名',
                    hint: '请输入用户名',
                    controller: _nameController,
                    validator: (v) {
                      if (v == null || v.trim().isEmpty) return '请输入用户名';
                      if (v.trim().length > 50) return '用户名过长';
                      return null;
                    },
                  ),
                  const SizedBox(height: 20),
                  HandDrawnInput(
                    label: '密码',
                    hint: '请输入密码',
                    controller: _passwordController,
                    obscureText: _obscurePassword,
                    validator: (v) {
                      if (v == null || v.isEmpty) return '请输入密码';
                      if (v.length < 4) return '密码至少4位';
                      return null;
                    },
                    suffixIcon: IconButton(
                      icon: Icon(
                        _obscurePassword
                            ? Icons.visibility_off
                            : Icons.visibility,
                        color: HandDrawnTheme.pencil,
                      ),
                      onPressed: () {
                        setState(() => _obscurePassword = !_obscurePassword);
                      },
                    ),
                  ),
                  const SizedBox(height: 32),
                  HandDrawnButton(
                    text: '登录',
                    onPressed: auth.loading ? null : _login,
                  ),
                  const SizedBox(height: 16),
                  TextButton(
                    onPressed: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => const RegisterScreen(),
                        ),
                      );
                    },
                    child: Text(
                      '没有账号？点击注册',
                      style: TextStyle(
                        fontFamily: 'Patrick Hand',
                        fontSize: 16,
                        color: HandDrawnTheme.blue,
                        decoration: TextDecoration.underline,
                        decorationStyle: TextDecorationStyle.dashed,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (auth.loading)
            const LoadingOverlay(message: '登录中...'),
        ],
      ),
    );
  }
}

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _nameController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _nameController.dispose();
    _passwordController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  Future<void> _register() async {
    if (!_formKey.currentState!.validate()) return;
    final auth = context.read<AuthProvider>();
    final success = await auth.register(
      _nameController.text.trim(),
      _passwordController.text.trim(),
    );
    if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(auth.error ?? '注册失败'),
          backgroundColor: HandDrawnTheme.accent,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return HandDrawnScaffold(
      title: '注册',
      showBackButton: true,
      body: Stack(
        children: [
          SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                children: [
                  const SizedBox(height: 40),
                  HandDrawnInput(
                    label: '用户名',
                    hint: '请输入用户名',
                    controller: _nameController,
                    validator: (v) {
                      if (v == null || v.trim().isEmpty) return '请输入用户名';
                      if (v.trim().length > 50) return '用户名过长';
                      return null;
                    },
                  ),
                  const SizedBox(height: 20),
                  HandDrawnInput(
                    label: '密码',
                    hint: '请输入密码（至少4位）',
                    controller: _passwordController,
                    obscureText: _obscurePassword,
                    validator: (v) {
                      if (v == null || v.isEmpty) return '请输入密码';
                      if (v.length < 4) return '密码至少4位';
                      return null;
                    },
                  ),
                  const SizedBox(height: 20),
                  HandDrawnInput(
                    label: '确认密码',
                    hint: '请再次输入密码',
                    controller: _confirmController,
                    obscureText: _obscurePassword,
                    validator: (v) {
                      if (v != _passwordController.text) return '两次密码不一致';
                      return null;
                    },
                    suffixIcon: IconButton(
                      icon: Icon(
                        _obscurePassword
                            ? Icons.visibility_off
                            : Icons.visibility,
                        color: HandDrawnTheme.pencil,
                      ),
                      onPressed: () {
                        setState(() => _obscurePassword = !_obscurePassword);
                      },
                    ),
                  ),
                  const SizedBox(height: 32),
                  HandDrawnButton(
                    text: '注册',
                    onPressed: auth.loading ? null : _register,
                  ),
                ],
              ),
            ),
          ),
          if (auth.loading)
            const LoadingOverlay(message: '注册中...'),
        ],
      ),
    );
  }
}